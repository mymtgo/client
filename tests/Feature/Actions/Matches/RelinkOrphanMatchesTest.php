<?php

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeOrphanMatch(bool $linkable = true, array $matchOverrides = []): MtgoMatch
{
    $account = Account::create([
        'username' => 'LocalPlayer',
        'active' => true,
        'tracked' => true,
    ]);

    $card = Card::factory()->create([
        'mtgo_id' => 100,
        'oracle_id' => 'oracle-fixed-id',
    ]);

    $signature = GenerateDeckSignature::run(collect([[
        'mtgo_id' => $card->mtgo_id,
        'quantity' => 4,
        'sideboard' => 'false',
    ]]));

    if ($linkable) {
        $deck = Deck::factory()->create(['account_id' => $account->id]);
        DeckVersion::factory()->create([
            'deck_id' => $deck->id,
            'signature' => $signature,
        ]);
    }

    $match = MtgoMatch::factory()->create(array_merge([
        'state' => MatchState::Complete,
        'deck_version_id' => null,
        'ended_at' => now()->subMinutes(5),
    ], $matchOverrides));

    $game = $match->games()->create([
        'mtgo_id' => fake()->unique()->numberBetween(100000, 999999),
        'started_at' => now()->subMinutes(10),
    ]);

    $deckJson = json_encode([[
        'CatalogId' => $card->mtgo_id,
        'Quantity' => 4,
        'InSideboard' => false,
    ]]);

    LogEvent::factory()->create([
        'event_type' => 'deck_used',
        'game_id' => $game->mtgo_id,
        'raw_text' => "12:00:00 [INF] (Deck|Used) {$deckJson}",
        'logged_at' => now()->subMinutes(10),
    ]);

    return $match;
}

it('relinks an orphan match when a matching deck version exists', function () {
    $match = makeOrphanMatch(linkable: true);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('leaves orphan matches unlinked when no matching deck version exists', function () {
    $match = makeOrphanMatch(linkable: false);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('does not touch matches that already have a deck linked', function () {
    $match = makeOrphanMatch(linkable: true);

    RelinkOrphanMatches::run();
    $linkedId = $match->fresh()->deck_version_id;
    expect($linkedId)->not->toBeNull();

    RelinkOrphanMatches::run();
    expect($match->fresh()->deck_version_id)->toBe($linkedId);
});

it('ignores matches in Started state', function () {
    $match = MtgoMatch::factory()->started()->create([
        'deck_version_id' => null,
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('ignores orphans outside the recency window', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    RelinkOrphanMatches::run(withinDays: 7);

    expect($match->fresh()->deck_version_id)->toBeNull();
});

it('relinks an orphan match in InProgress state', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::InProgress,
        'started_at' => now()->subMinutes(5),
        'ended_at' => null,
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('relinks an orphan match in Ended state', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::Ended,
        'started_at' => now()->subMinutes(5),
        'ended_at' => now()->subMinutes(1),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->not->toBeNull();
});

it('skips matches outside the recency window by started_at', function () {
    $match = makeOrphanMatch(linkable: true, matchOverrides: [
        'state' => MatchState::Complete,
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| League backfill
|--------------------------------------------------------------------------
*/

function makeJoinedStateEvent(string $matchToken, string $leagueToken, string $format = 'CPAUP'): LogEvent
{
    $rawText = <<<TEXT
12:00:00 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in LeagueMatchJoinedEventUnderwayState) Processor: LeagueMatchJoinedEventUnderwayState Message: {"MatchToken":"{$matchToken}","MatchID":123456789,"GameID":987654321} Receiver:
League Token={$leagueToken}
PlayFormatCd={$format}
GameStructureCd=League
TEXT;

    return LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'context' => 'Game Management|Processing Registered Handler for GsMessageMessage in LeagueMatchJoinedEventUnderwayState',
        'match_token' => $matchToken,
        'raw_text' => $rawText,
        'logged_at' => now()->subMinutes(5),
    ]);
}

it('assigns a league to an orphan match when the joined-state event carries a League Token', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => null,
        'tournament_event_id' => null,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    makeJoinedStateEvent($match->token, 'league-token-pauper-1');

    RelinkOrphanMatches::run();

    $match->refresh();
    expect($match->league_id)->not->toBeNull();
    expect($match->league->token)->toBe('league-token-pauper-1');
});

it('reuses an existing active league when re-running the league backfill on the same match', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => null,
        'tournament_event_id' => null,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    makeJoinedStateEvent($match->token, 'league-token-pauper-2');

    RelinkOrphanMatches::run();
    $firstLeagueId = $match->fresh()->league_id;

    RelinkOrphanMatches::run();

    expect($match->fresh()->league_id)->toBe($firstLeagueId);
    expect(League::where('token', 'league-token-pauper-2')->count())->toBe(1);
});

it('does not backfill a league when no joined-state event exists for the match', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => null,
        'tournament_event_id' => null,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->league_id)->toBeNull();
});

it('does not backfill a league for tournament matches', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => null,
        'tournament_event_id' => 12345,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    makeJoinedStateEvent($match->token, 'league-token-pauper-3');

    RelinkOrphanMatches::run();

    expect($match->fresh()->league_id)->toBeNull();
});

it('does not backfill a league when the joined-state event has no League Token', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => null,
        'tournament_event_id' => null,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'context' => 'Game Management|Processing Registered Handler for GsMessageMessage in MatchJoinedEventUnderwayState',
        'match_token' => $match->token,
        'raw_text' => '12:00:00 [INF] (foo|bar) Receiver:'."\n".'PlayFormatCd=CMODERN'."\n".'GameStructureCd=Constructed',
        'logged_at' => now()->subMinutes(5),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->league_id)->toBeNull();
});

it('leaves an already-assigned league untouched', function () {
    $deckVersion = DeckVersion::factory()->create();
    $league = League::factory()->create([
        'token' => 'pre-existing-league',
        'state' => LeagueState::Active,
    ]);

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'deck_version_id' => $deckVersion->id,
        'league_id' => $league->id,
        'tournament_event_id' => null,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(1),
    ]);

    makeJoinedStateEvent($match->token, 'different-token');

    RelinkOrphanMatches::run();

    expect($match->fresh()->league_id)->toBe($league->id);
});

it('inherits the deck version from sibling rounds of the same tournament', function () {
    // Round 1 starts before SyncDecks has picked up a freshly finished list, so
    // it can reach Complete unlinked — and once its log events are pruned it can
    // never relink from the log again. The other rounds of a locked event list
    // are authoritative.
    $tournament = Tournament::factory()->create();
    $deckVersion = DeckVersion::factory()->create();

    $roundOne = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 1,
        'deck_version_id' => null,
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 2,
        'deck_version_id' => $deckVersion->id,
        'started_at' => now()->subDays(30),
        'ended_at' => now()->subDays(30),
    ]);

    RelinkOrphanMatches::run();

    expect($roundOne->fresh()->deck_version_id)->toBe($deckVersion->id);
});

it('does not inherit when sibling rounds disagree on the deck version', function () {
    $tournament = Tournament::factory()->create();

    $roundOne = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 1,
        'deck_version_id' => null,
        'started_at' => now()->subMinutes(30),
        'ended_at' => now()->subMinutes(20),
    ]);

    foreach ([DeckVersion::factory()->create(), DeckVersion::factory()->create()] as $index => $version) {
        MtgoMatch::factory()->create([
            'state' => MatchState::Complete,
            'tournament_id' => $tournament->id,
            'tournament_round' => $index + 2,
            'deck_version_id' => $version->id,
            'started_at' => now()->subMinutes(30),
            'ended_at' => now()->subMinutes(20),
        ]);
    }

    RelinkOrphanMatches::run();

    expect($roundOne->fresh()->deck_version_id)->toBeNull();
});

it('leaves a tournament match that already has a deck version untouched', function () {
    $tournament = Tournament::factory()->create();
    $own = DeckVersion::factory()->create();
    $sibling = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 1,
        'deck_version_id' => $own->id,
        'started_at' => now()->subMinutes(30),
        'ended_at' => now()->subMinutes(20),
    ]);

    MtgoMatch::factory()->create([
        'state' => MatchState::Complete,
        'tournament_id' => $tournament->id,
        'tournament_round' => 2,
        'deck_version_id' => $sibling->id,
        'started_at' => now()->subMinutes(30),
        'ended_at' => now()->subMinutes(20),
    ]);

    RelinkOrphanMatches::run();

    expect($match->fresh()->deck_version_id)->toBe($own->id);
});
