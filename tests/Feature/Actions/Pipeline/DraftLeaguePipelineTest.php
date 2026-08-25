<?php

use App\Actions\Logs\IngestLogInstance;
use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\Draft;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => mockMtgoManagerForPipeline());

it('projects the hobbit fixture end to end', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    runPipelineUntilIdle();

    $league = League::where('event_id', 11039)->firstOrFail();
    $draft = $league->draft;

    expect($league->kind)->toBe(LeagueKind::Draft)
        ->and($league->set_code)->toBe('HOB')
        ->and($league->mtgo_course_id)->toBe(35746768)
        ->and($league->token)->toBe('48a2e914-f2ee-4fce-a4ad-47e396488889')
        ->and($draft->state)->toBe(DraftState::Finished)
        ->and($draft->picks()->count())->toBe(42);

    $leagueMatches = $league->matches()->orderBy('started_at')->get();
    expect($leagueMatches)->toHaveCount(3)
        ->and($leagueMatches->pluck('mtgo_id')->all())->toBe(['289328482', '289328793', '289328829'])
        ->and($leagueMatches->pluck('deck_version_id')->filter())->toHaveCount(3);

    // One registered snapshot per league match, even when match 3 reuses match 2's deck.
    expect($league->deckSnapshots()->where('source', 'registered')->count())->toBe(3);

    $deck = Deck::where('mtgo_id', 'limited:791bacca-caea-4d88-b6c7-3bc067d412c2')->firstOrFail();
    expect($deck->format)->toBe('Limited')
        ->and($deck->versions()->count())->toBe(2)
        ->and($league->fresh()->deck_version_id)->toBe($leagueMatches->last()->deck_version_id);

    // The pre-draft constructed match (60 + 15) must not attach to the draft league.
    $constructed = MtgoMatch::where('mtgo_id', '289328158')->first();
    expect($constructed)->not->toBeNull()
        ->and($constructed->league_id)->not->toBe($league->id);
});

it('projects the incomplete fixture as a finished draft with no matches', function () {
    ingestFixtureLog('mtgo_draft_incomplete.log', '2026-07-09');

    runPipelineUntilIdle();

    $league = League::where('event_id', 10814)->firstOrFail();

    expect($league->kind)->toBe(LeagueKind::Draft)
        ->and($league->set_code)->toBe('MSH')
        ->and($league->state)->toBe(LeagueState::Active)
        ->and($league->draft->state)->toBe(DraftState::Finished)
        ->and($league->draft->picks()->count())->toBe(42)
        ->and($league->matches()->count())->toBe(0)
        ->and($league->deckSnapshots()->count())->toBe(0);
});

it('is idempotent across a second full replay', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    runPipelineUntilIdle();

    $league = League::where('event_id', 11039)->firstOrFail();
    $league->draft->picks()->where('ordinal', 1)->update(['note' => 'kept']);

    LogEvent::query()->update(['processed_at' => null]);
    runPipelineUntilIdle();

    expect(League::where('event_id', 11039)->count())->toBe(1)
        ->and(Draft::count())->toBe(1)
        ->and($league->fresh()->draft->picks()->count())->toBe(42)
        ->and($league->fresh()->draft->picks()->where('ordinal', 1)->value('note'))->toBe('kept')
        ->and($league->deckSnapshots()->count())->toBe(3)
        ->and(Deck::where('format', 'Limited')->count())->toBe(1);
});

it('separates two entries of the same league into two runs', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    runPipelineUntilIdle();

    // Second entry: same LeagueID, new CourseID / DraftToken / pack ids / match tokens.
    // Both runs draft an identical set of packs (this fixture is a literal
    // replay of the same log content, not a genuinely different draft), so
    // the registered deck for every match in run two fits run two's own
    // pool trivially. This does not exercise AssignLeague's 2.4 pool-fit
    // guard rejecting a mismatched deck; that path is covered by the unit
    // tests in AssignLeagueTest.php. What this test isolates is step 2.2:
    // whether AssignLeague finds the correct, already-created run-two
    // league across a deck_version_id change between run two's own matches.
    $source = base_path('tests/Fixtures/logs/mtgo_draft_hobbit.log');
    $second = sys_get_temp_dir().'/mymtgo_reentry_'.bin2hex(random_bytes(4)).'.log';
    register_shutdown_function(static function () use ($second): void {
        @unlink($second);
    });
    $text = file_get_contents($source);
    $text = str_replace('791bacca-caea-4d88-b6c7-3bc067d412c2', 'aaaaaaaa-0000-4000-8000-000000000002', $text);
    $text = str_replace('"CourseID":35746768', '"CourseID":35746999', $text);
    $text = str_replace('"DraftID":6781001', '"DraftID":6781999', $text);
    $text = str_replace('"DraftGroupID":6781001', '"DraftGroupID":6781999', $text);
    $text = str_replace('2026-08-22T', '2026-08-23T', $text);
    $text = preg_replace('/"PackID":1436820(9\d)/', '"PackID":1436828$1', $text);
    $text = preg_replace('/"PackID":1436821(\d\d)/', '"PackID":1436829$1', $text);
    // GameID appears in three different textual shapes across this fixture
    // (a bare JSON "GameID": key, a "Game ID: N, Match ID: N" header line
    // used for game_state_update classification, and a "Deck Used in Game
    // ID: N" header line for deck_used) that a single format-specific regex
    // cannot all catch at once. Rewriting only one shape desyncs the
    // game_id ClassifyLogEvent extracts for game_state_update/deck_used
    // events from the id embedded in the JSON payloads, which orphans every
    // game for the second entry. Replace each known id directly instead, so
    // every occurrence in every shape moves together.
    foreach ([959762288, 959762636, 959762762, 959763634, 959764088, 959764748, 959764884] as $gameId) {
        $text = preg_replace('/(?<!\d)'.$gameId.'(?!\d)/', (string) ($gameId + 1000000), $text);
    }
    foreach (['08efb7ad-3670-4fab-b015-97cea823cde1' => 'bbbbbbbb-0000-4000-8000-000000000001',
        '614c280d-a378-4c64-bcd1-73f1739fd192' => 'bbbbbbbb-0000-4000-8000-000000000002',
        '6250e242-fcec-4d44-b642-9268b6581719' => 'bbbbbbbb-0000-4000-8000-000000000003'] as $from => $to) {
        $text = str_replace($from, $to, $text);
    }
    $text = str_replace(['289328482', '289328793', '289328829'], ['289329482', '289329793', '289329829'], $text);
    file_put_contents($second, $text);
    $mtime = Carbon::parse('2026-08-23 13:00:00', 'UTC')->getTimestamp();
    touch($second, $mtime, $mtime);
    IngestLogInstance::run($second);

    runPipelineUntilIdle();

    $runs = League::where('event_id', 11039)->orderBy('started_at')->get();

    expect($runs)->toHaveCount(2)
        ->and($runs->first()->state)->toBe(LeagueState::Complete)
        ->and($runs->last()->state)->toBe(LeagueState::Active)
        ->and(Draft::count())->toBe(2)
        ->and($runs->first()->matches()->count())->toBe(3)
        ->and($runs->last()->matches()->count())->toBe(3)
        ->and($runs->last()->draft->picks()->count())->toBe(42)
        ->and($runs->last()->deck_version_id)->not->toBeNull();

    // Every run-two match plays an identical, already-seen decklist (same
    // reason the pool guard isn't exercised here), so DetermineMatchDeck's
    // signature lookup resolves each one straight to a DeckVersion minted
    // during run one, before AdvanceMatchState's limited block ever needs
    // to mint a new one from the registered snapshot. Run two's league
    // therefore syncs onto that shared Deck (limited:791bacca-...) rather
    // than minting its own limited:aaaaaaaa-...-0002 Deck, and only one
    // Limited Deck exists in total.
    expect(Deck::where('format', 'Limited')->count())->toBe(1)
        ->and($runs->last()->deck_version_id)->toBe($runs->first()->deck_version_id);
});

it('adopts the course-less league its matches created when the draft lines arrive later', function () {
    // App-not-watching: the draft happened while mymtgo was closed, so
    // AssignLeague saw the matches first (spec re-entry rule 4) and minted a
    // draft league with no CourseID and no Draft row. Feeding the draft lines
    // afterwards must adopt that league, not mint a second one for the run.
    $stripped = sys_get_temp_dir().'/mymtgo_nodraft_'.bin2hex(random_bytes(4)).'.log';
    register_shutdown_function(static function () use ($stripped): void {
        @unlink($stripped);
    });

    $markers = [
        'FlsBoosterDraft',
        'LeagueStanding_t in Draft',
        'FlsLeagueUserJoinDraftRespMessage',
        'SubmitDraftSelectionsAction',
        'Draft Created',
        'Draft State Changed',
    ];

    $kept = array_filter(
        file(base_path('tests/Fixtures/logs/mtgo_draft_hobbit.log')),
        fn (string $line): bool => ! Str::contains($line, $markers),
    );
    file_put_contents($stripped, implode('', $kept));
    $mtime = Carbon::parse('2026-08-22 13:00:00', 'UTC')->getTimestamp();
    touch($stripped, $mtime, $mtime);

    IngestLogInstance::run($stripped);
    runPipelineUntilIdle();

    $orphanRun = League::where('event_id', 11039)->firstOrFail();

    expect($orphanRun->kind)->toBe(LeagueKind::Draft)
        ->and($orphanRun->mtgo_course_id)->toBeNull()
        ->and($orphanRun->matches()->count())->toBe(3)
        ->and($orphanRun->deckSnapshots()->where('source', 'registered')->count())->toBe(3)
        ->and(Draft::count())->toBe(0);

    ingestFixtureLog('mtgo_draft_hobbit.log');
    runPipelineUntilIdle();

    $runs = League::where('event_id', 11039)->get();

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->id)->toBe($orphanRun->id)
        ->and($runs->first()->mtgo_course_id)->toBe(35746768)
        ->and($runs->first()->state)->toBe(LeagueState::Active)
        ->and($runs->first()->matches()->count())->toBe(3)
        ->and($runs->first()->draft)->not->toBeNull()
        ->and($runs->first()->draft->picks()->count())->toBe(42)
        ->and(Draft::count())->toBe(1);
});
