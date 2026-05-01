<?php

use App\Actions\Matches\AssignLeague;
use App\Enums\LeagueState;
use App\Models\DeckVersion;
use App\Models\League;
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
