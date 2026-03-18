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
| Phantom League Assignment
|--------------------------------------------------------------------------
*/

it('sets deck_version_id on phantom leagues', function () {
    $deckVersion = DeckVersion::factory()->create();
    $match = makeMatchWithDeck($deckVersion);

    // Phantom league — no League Token
    $gameMeta = [
        'League Token' => '',
        'PlayFormatCd' => 'CStandard',
        'GameStructureCd' => 'Constructed',
    ];

    callAssignLeague($match, $gameMeta);

    $match->refresh();
    expect($match->league)->not->toBeNull();
    expect((bool) $match->league->phantom)->toBeTrue();
    expect($match->league->deck_version_id)->toBe($deckVersion->id);
});
