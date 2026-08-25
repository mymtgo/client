<?php

use App\Actions\Matches\AssignLeague;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function callAssignLeague(MtgoMatch $match, array $gameMeta): void
{
    AssignLeague::run($match, $gameMeta);
}

function makeMatchWithDeck(DeckVersion $deckVersion, array $overrides = []): MtgoMatch
{
    return MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $deckVersion->id,
    ], $overrides));
}

function defaultGameMeta(string $token = 'league-token-123', string $format = 'CStandard'): array
{
    return [
        'League Token' => $token,
        'PlayFormatCd' => $format,
        'GameStructureCd' => 'Constructed',
    ];
}

/**
 * A match_deck_registered log line, matching the FlsMatchDeckGetRespMessage
 * shape AssignLeague's registeredMainDeck() decodes via RepairJson.
 *
 * @param  array<int, array{0: int, 1: int, 2: bool}>  $cards  [catalog_id, quantity, in_sideboard]
 */
function matchDeckRegisteredLogLine(string $matchToken, int $matchId, array $cards): string
{
    $json = json_encode(['MatchToken' => $matchToken, 'MatchID' => $matchId, 'Cards' => array_map(fn ($c) => [
        'CatalogID' => $c[0], 'Annotation' => 0, 'PermissionTypeCode' => 0, 'Quantity' => $c[1], 'InSideboard' => $c[2],
    ], $cards), 'ResponseCode' => 1]);

    return "12:37:19 [INF] (BaseClient|Inbound: FlsMatchDeckGetRespMessage) {$json}";
}

/*
|--------------------------------------------------------------------------
| Real League Assignment
|--------------------------------------------------------------------------
*/

it('creates a league for a new token + deck version', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
    expect($match->league->token)->toBe('league-token-123');
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});

it('reuses existing league for same token + same deck version', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deckVersion);
    $match2 = makeMatchWithDeck($deckVersion);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->toBe($match2->league_id);
});

it('creates a new league for same token + different deck version', function () {
    // Distinguishes the app-not-watching re-entry case: user dropped run A
    // (deck v1) and re-joined run B (deck v2) while the app was closed.
    // No join event was seen, so AssignLeague must still split on deck.
    $deck1Version = DeckVersion::factory()->create();
    $deck2Version = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deck1Version);
    $match2 = makeMatchWithDeck($deck2Version);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->not->toBe($match2->league_id);
    expect($match1->league->token)->toBe('league-token-123');
    expect($match2->league->token)->toBe('league-token-123');
});

it('marks previous run as partial when new run created for same token', function () {
    $deck1Version = DeckVersion::factory()->create();
    $deck2Version = DeckVersion::factory()->create();
    $match1 = makeMatchWithDeck($deck1Version);
    $match2 = makeMatchWithDeck($deck2Version);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    expect($match1->league->state)->toBe(LeagueState::Partial);
});

it('attaches first match to a ProcessLeagueEvents-created league with null deck_version_id', function () {
    // ProcessLeagueEvents creates the League at join time without a deck
    // context (deck_version_id is null until the first match arrives).
    // AssignLeague must reuse that league instead of minting a duplicate.
    $deckV1 = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'format' => 'CStandard',
        'event_id' => 10397,
        'deck_version_id' => null,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckV1);

    callAssignLeague($match, defaultGameMeta());

    expect($match->fresh()->league_id)->toBe($league->id);
    expect(League::count())->toBe(1);
});

it('backfills deck_version_id on a ProcessLeagueEvents-created league on first match', function () {
    $deckV1 = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'format' => 'CStandard',
        'event_id' => 10397,
        'deck_version_id' => null,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckV1);

    callAssignLeague($match, defaultGameMeta());

    expect($league->fresh()->deck_version_id)->toBe($deckV1->id);
});

it('falls back to token-only matching when deck version is null', function () {
    $match1 = MtgoMatch::factory()->create(['deck_version_id' => null]);
    $match2 = MtgoMatch::factory()->create(['deck_version_id' => null]);

    callAssignLeague($match1, defaultGameMeta());
    callAssignLeague($match2, defaultGameMeta());

    $match1->refresh();
    $match2->refresh();
    expect($match1->league_id)->toBe($match2->league_id);
});

it('sets deck_version_id on the league when created', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});

it('is idempotent — calling assignLeague twice with same match produces same result', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());
    $match->refresh();
    $firstLeagueId = $match->league_id;

    // Reset league_id to simulate re-processing
    $match->update(['league_id' => null]);
    callAssignLeague($match, defaultGameMeta());
    $match->refresh();

    expect($match->league_id)->toBe($firstLeagueId);
    expect(League::where('token', 'league-token-123')->count())->toBe(1);
});

it('assigns null-deck match to existing league when deck-versioned league exists for same token', function () {
    $deckVersion = DeckVersion::factory()->create();
    $matchWithDeck = makeMatchWithDeck($deckVersion);
    $matchWithoutDeck = MtgoMatch::factory()->create(['deck_version_id' => null]);

    callAssignLeague($matchWithDeck, defaultGameMeta());
    callAssignLeague($matchWithoutDeck, defaultGameMeta());

    $matchWithDeck->refresh();
    $matchWithoutDeck->refresh();

    // The null-deck match falls back to [token, format] lookup which finds
    // the existing league (firstOrCreate matches on provided keys only)
    expect($matchWithoutDeck->league_id)->toBe($matchWithDeck->league_id);
});

/*
|--------------------------------------------------------------------------
| Re-entry After Completion
|--------------------------------------------------------------------------
*/

it('creates a new league when re-entering with same deck after completing 5 matches', function () {
    $deckVersion = DeckVersion::factory()->create();

    // First run: 5 matches, league marked complete
    $league1 = League::factory()->complete()->create([
        'token' => 'league-token-123',
        'format' => 'CStandard',
        'deck_version_id' => $deckVersion->id,
    ]);

    // Create 5 completed matches in the league
    for ($i = 0; $i < 5; $i++) {
        makeMatchWithDeck($deckVersion, ['league_id' => $league1->id]);
    }

    // New match in the same league with the same deck (re-entry)
    $newMatch = makeMatchWithDeck($deckVersion);
    callAssignLeague($newMatch, defaultGameMeta());

    $newMatch->refresh();

    // Should be in a NEW league, not the completed one
    expect($newMatch->league_id)->not->toBe($league1->id);
    expect($newMatch->league->token)->toBe('league-token-123');
    expect($newMatch->league->deck_version_id)->toBe($deckVersion->id);
});

it('does not attach a 6th match to an Active league at the 5-match cap', function () {
    // Backstop for the unwatched re-entry edge: if app missed the drop and
    // re-join events but ingested run A's matches, the Active league is
    // already at 5 matches. A new match for run B must NOT glue onto it.
    $deckVersion = DeckVersion::factory()->create();

    $fullLeague = League::factory()->create([
        'token' => 'league-token-123',
        'format' => 'CStandard',
        'deck_version_id' => $deckVersion->id,
        'state' => LeagueState::Active,
    ]);

    for ($i = 0; $i < 5; $i++) {
        makeMatchWithDeck($deckVersion, ['league_id' => $fullLeague->id]);
    }

    $newMatch = makeMatchWithDeck($deckVersion);
    callAssignLeague($newMatch, defaultGameMeta());

    $newMatch->refresh();
    expect($newMatch->league_id)->not->toBe($fullLeague->id);
    expect($newMatch->league->token)->toBe('league-token-123');
    expect($fullLeague->fresh()->state)->toBe(LeagueState::Complete);
});

it('reuses active league when it has fewer than 5 matches', function () {
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'format' => 'CStandard',
        'deck_version_id' => $deckVersion->id,
    ]);

    makeMatchWithDeck($deckVersion, ['league_id' => $league->id]);

    // Second match, same league, same deck — should reuse
    $match2 = makeMatchWithDeck($deckVersion);
    callAssignLeague($match2, defaultGameMeta());

    $match2->refresh();
    expect($match2->league_id)->toBe($league->id);
});

/*
|--------------------------------------------------------------------------
| Event ID Lookup
|--------------------------------------------------------------------------
*/

it('finds league by event_id when available in gameMeta', function () {
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'event_id' => 10397,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckVersion);

    $gameMeta = defaultGameMeta();
    $gameMeta['EventId'] = '10397';

    callAssignLeague($match, $gameMeta);

    $match->refresh();
    expect($match->league_id)->toBe($league->id);
});

it('falls back to composite key when event_id not in gameMeta', function () {
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'event_id' => 10397,
        'deck_version_id' => $deckVersion->id,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->toBe($league->id);
});

it('creates league reactively when no pre-existing league found', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, defaultGameMeta());

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
    expect($match->league->token)->toBe('league-token-123');
});

it('attaches match to a ProcessLeagueEvents-created league when format codes diverge', function () {
    // Regression: MTGO emits PlayFormatCd=Modern in panel-view logs but
    // PlayFormatCd=CMODERN in match logs. ProcessLeagueEvents stored the
    // panel-view value; AssignLeague step 2 used to filter by format and
    // miss the existing league, creating a duplicate.
    $deckVersion = DeckVersion::factory()->create();

    $league = League::factory()->create([
        'token' => 'league-token-123',
        'format' => 'Modern', // panel-view code
        'event_id' => 10628,
        'deck_version_id' => null,
        'state' => LeagueState::Active,
    ]);

    $match = makeMatchWithDeck($deckVersion);

    callAssignLeague($match, [
        'League Token' => 'league-token-123',
        'PlayFormatCd' => 'CMODERN', // match-log code
        'GameStructureCd' => 'Modern',
    ]);

    expect($match->fresh()->league_id)->toBe($league->id);
    expect(League::count())->toBe(1);
});

it('leaves match unassigned when no league token is present', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    AssignLeague::run($match, [
        'PlayFormatCd' => 'CStandard',
        'GameStructureCd' => 'Match',
        // no 'League Token' key
    ]);

    expect($match->fresh()->league_id)->toBeNull();
    expect(League::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Limited Re-entry Pool Guard
|--------------------------------------------------------------------------
*/

it('mints a new draft league when the registered deck does not fit the current pool', function () {
    // Match logs never carry EventId in a parseable form, so step 1 always
    // misses for real matches. The guard has to catch this after step 2
    // (token + Active) resolves the stale run.
    $token = 'league-token-draft-guard';
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);
    $draft = Draft::factory()->for($league)->finished()->create();
    $pool = range(1000, 1022);
    foreach (array_values($pool) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
    }

    $mismatched = range(5000, 5022);
    foreach ($mismatched as $catalogId) {
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    $match = MtgoMatch::factory()->create(['token' => 'm-guard-mismatch']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-guard-mismatch',
        'raw_text' => matchDeckRegisteredLogLine('m-guard-mismatch', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $mismatched)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(2)
        ->and($league->fresh()->state)->toBe(LeagueState::Partial)
        ->and($match->league_id)->not->toBe($league->id)
        ->and($match->league->kind)->toBe(LeagueKind::Draft)
        ->and($match->league->token)->toBe($token);
});

it('reuses the draft league when the registered deck fits the current pool', function () {
    $token = 'league-token-draft-guard-fits';
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);
    $draft = Draft::factory()->for($league)->finished()->create();
    $pool = range(1000, 1022);
    foreach (array_values($pool) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    $match = MtgoMatch::factory()->create(['token' => 'm-guard-fits']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-guard-fits',
        'raw_text' => matchDeckRegisteredLogLine('m-guard-fits', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $pool)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(1)
        ->and($match->league_id)->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Active);
});

it('leaves a constructed league untouched by the pool guard', function () {
    $token = 'league-token-constructed-guard';
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Constructed,
        'state' => LeagueState::Active,
    ]);

    $match = MtgoMatch::factory()->create(['token' => 'm-guard-constructed']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-guard-constructed',
        'raw_text' => matchDeckRegisteredLogLine('m-guard-constructed', (int) $match->mtgo_id, [[9999, 4, false]]),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'CStandard']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(1)
        ->and($match->league_id)->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Active);
});

it('leaves an unfinished draft league alone even when the registered deck does not fit the partial pool', function () {
    // A catch-up replay can still be projecting picks when this runs. The
    // pool isn't fully known yet, so a mismatch here can't be trusted: the
    // guard must not split the league just because the pool looks small.
    $token = 'league-token-draft-unfinished';
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);
    $draft = Draft::factory()->for($league)->create();
    $pool = range(1000, 1011);
    foreach (array_values($pool) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
    }

    $mismatched = range(5000, 5022);
    foreach ($mismatched as $catalogId) {
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    $match = MtgoMatch::factory()->create(['token' => 'm-guard-unfinished']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-guard-unfinished',
        'raw_text' => matchDeckRegisteredLogLine('m-guard-unfinished', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $mismatched)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(1)
        ->and($match->league_id)->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Active);
});

it('reuses the draft league across a deck-version change when the registered deck fits the pool', function () {
    // A synthetic Limited DeckVersion is minted after the run's first match
    // (in AdvanceMatchState, which runs after AssignLeague), so by the
    // second match the match's deck_version_id already differs from the
    // league's stored value. The dv-split heuristic in step 2 must not
    // treat that as a new run for limited leagues; the pool guard is the
    // real re-entry detector for those.
    $token = 'league-token-draft-dv-change';
    $deckV1 = DeckVersion::factory()->create();
    $deckV2 = DeckVersion::factory()->create();
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'deck_version_id' => $deckV1->id,
    ]);
    $draft = Draft::factory()->for($league)->finished()->create();
    $pool = range(1000, 1022);
    foreach (array_values($pool) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    $match = MtgoMatch::factory()->create(['token' => 'm-dv-change', 'deck_version_id' => $deckV2->id]);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-dv-change',
        'raw_text' => matchDeckRegisteredLogLine('m-dv-change', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $pool)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(1)
        ->and($match->league_id)->toBe($league->id)
        ->and($league->fresh()->deck_version_id)->toBe($deckV1->id);
});

it('mints a new constructed league on a deck-version change, proving the dv-split heuristic is untouched', function () {
    $token = 'league-token-constructed-dv-change';
    $deckV1 = DeckVersion::factory()->create();
    $deckV2 = DeckVersion::factory()->create();
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Constructed,
        'state' => LeagueState::Active,
        'deck_version_id' => $deckV1->id,
    ]);

    $match = MtgoMatch::factory()->create(['token' => 'm-dv-change-constructed', 'deck_version_id' => $deckV2->id]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'CStandard']);

    $match->refresh();
    expect(League::where('token', $token)->count())->toBe(2)
        ->and($match->league_id)->not->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Partial);
});

it('does not backfill deck_version_id onto a limited league in step 2', function () {
    // Guards the kind check on the dv backfill added alongside step 2.2:
    // for limited leagues, AdvanceMatchState is the sole owner of
    // league.deck_version_id (it syncs to whichever deck a match actually
    // played, match to match). If step 2 backfilled from whichever match
    // happens to attach first instead, a later match with a different but
    // equally legitimate deck_version_id would miss the league via step
    // 2's own dv filter and mint a spurious extra league.
    $token = 'league-token-draft-no-backfill';
    $deckVersion = DeckVersion::factory()->create();
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'deck_version_id' => null,
    ]);

    $match = MtgoMatch::factory()->create(['token' => 'm-no-backfill', 'deck_version_id' => $deckVersion->id]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $match->refresh();
    expect($match->league_id)->toBe($league->id)
        ->and($league->fresh()->deck_version_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Placeholder Token Healing
|--------------------------------------------------------------------------
*/

it('heals a placeholder draft token instead of splitting the run', function () {
    // ResolveDraftLeague mints "draft-{leagueId}-{courseId}" when the draft
    // lines arrive before any league_joined panel view. Steps 2 and 2.2 look
    // up the real League Token, so without healing the run splits forever.
    $token = '48a2e914-f2ee-4fce-a4ad-47e396488889';
    $league = League::factory()->create([
        'token' => 'draft-11039-35746768',
        'event_id' => 11039,
        'mtgo_course_id' => 35746768,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'started_at' => now()->subHour(),
    ]);

    LogEvent::factory()->create([
        'event_type' => 'league_joined',
        'match_token' => $token,
        'match_id' => '11039',
        'logged_at' => now()->subMinutes(30),
    ]);

    $match = MtgoMatch::factory()->create(['token' => 'm-heal-placeholder']);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOBHOBHOB']);

    $match->refresh();
    expect(League::count())->toBe(1)
        ->and($match->league_id)->toBe($league->id)
        ->and($league->fresh()->token)->toBe($token);
});

it('does not heal a placeholder league whose pool the registered deck fails', function () {
    // Unwatched re-entry: the placeholder run is a previous draft, and its
    // pool says so. The match must mint its own run rather than glue on.
    $token = '48a2e914-f2ee-4fce-a4ad-47e396488889';
    $league = League::factory()->create([
        'token' => 'draft-11039-35746768',
        'event_id' => 11039,
        'mtgo_course_id' => 35746768,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
        'started_at' => now()->subHour(),
    ]);
    $draft = Draft::factory()->for($league)->finished()->create();
    foreach (array_values(range(1000, 1022)) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
    }

    $mismatched = range(5000, 5022);
    foreach ($mismatched as $catalogId) {
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    LogEvent::factory()->create([
        'event_type' => 'league_joined',
        'match_token' => $token,
        'match_id' => '11039',
        'logged_at' => now()->subMinutes(30),
    ]);

    $match = MtgoMatch::factory()->create(['token' => 'm-heal-mismatch']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-heal-mismatch',
        'raw_text' => matchDeckRegisteredLogLine('m-heal-mismatch', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $mismatched)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOBHOBHOB']);

    $match->refresh();
    expect(League::count())->toBe(2)
        ->and($match->league_id)->not->toBe($league->id)
        ->and($league->fresh()->token)->toBe('draft-11039-35746768');
});

it('completes a stale draft run rejected by the pool guard once it has its matches', function () {
    // Three matches is a run MTGO considers played out, so the rejected
    // league is Complete with a completion timestamp, not Partial.
    $token = 'league-token-draft-guard-complete';
    $league = League::factory()->create([
        'token' => $token,
        'kind' => LeagueKind::Draft,
        'state' => LeagueState::Active,
    ]);
    MtgoMatch::factory()->count(3)->create(['league_id' => $league->id]);

    $draft = Draft::factory()->for($league)->finished()->create();
    foreach (array_values(range(1000, 1022)) as $i => $catalogId) {
        DraftPick::factory()->for($draft)->create(['ordinal' => $i + 1, 'picked_catalog_id' => $catalogId]);
    }

    $mismatched = range(5000, 5022);
    foreach ($mismatched as $catalogId) {
        Card::factory()->create(['mtgo_id' => (string) $catalogId, 'type' => 'Creature']);
    }

    $match = MtgoMatch::factory()->create(['token' => 'm-guard-complete']);
    LogEvent::factory()->create([
        'event_type' => 'match_deck_registered',
        'match_token' => 'm-guard-complete',
        'raw_text' => matchDeckRegisteredLogLine('m-guard-complete', (int) $match->mtgo_id, array_map(fn ($id) => [$id, 1, false], $mismatched)),
    ]);

    callAssignLeague($match, ['League Token' => $token, 'PlayFormatCd' => 'DHOB']);

    $league->refresh();
    expect($league->state)->toBe(LeagueState::Complete)
        ->and($league->completed_at)->not->toBeNull()
        ->and($match->fresh()->league_id)->not->toBe($league->id);
});
