<?php

use App\Actions\Matches\SubmitMatchToApi;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\GameDeck;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

/**
 * Build a submittable match with one game and resolved player/opponent archetypes.
 * Opponent's seen cards come from game_decks.
 *
 * @param  array<int, array<int, array{mtgo_id: int, quantity: int}>>  $opponentDeckJsonPerGame
 */
function makeSubmittableMatch(array $opponentDeckJsonPerGame, ?League $league = null): MtgoMatch
{
    $deckVersion = DeckVersion::factory()->create();
    $account = Account::factory()->create(['username' => 'localplayer_'.uniqid(), 'active' => true]);

    $match = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $deckVersion->id,
        'league_id' => $league?->id,
        'tournament_event_id' => null,
        'account_id' => $account->id,
    ]);

    foreach ($opponentDeckJsonPerGame as $deckJson) {
        $game = Game::factory()->create([
            'match_id' => $match->id,
            'won' => true,
            'local_instance' => 2,
            'opp_instance' => 1,
            'local_on_play' => true,
        ]);

        GameDeck::create([
            'game_id' => $game->id,
            'is_opponent' => false,
            'deck_json' => [],
        ]);

        GameDeck::create([
            'game_id' => $game->id,
            'is_opponent' => true,
            'deck_json' => $deckJson,
        ]);
    }

    $playerArchetype = Archetype::factory()->create();
    $opponentArchetype = Archetype::factory()->create();

    $match->archetypes()->create([
        'is_opponent' => false,
        'archetype_id' => $playerArchetype->id,
        'confidence' => 0.9,
    ]);

    $match->archetypes()->create([
        'is_opponent' => true,
        'archetype_id' => $opponentArchetype->id,
        'confidence' => 0.9,
    ]);

    return $match;
}

it('sends league_run and opponent_deck when reporting a match', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $league = League::factory()->create();

    $match = makeSubmittableMatch([
        [
            ['mtgo_id' => 1001, 'quantity' => 2],
            ['mtgo_id' => 1002, 'quantity' => 1],
        ],
    ], $league);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) use ($league) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['league_run'])->toBe($league->id);
        expect($request['opponent_deck'])->toEqualCanonicalizing([
            ['mtgo_id' => 1001, 'quantity' => 2, 'zone' => 'main'],
            ['mtgo_id' => 1002, 'quantity' => 1, 'zone' => 'main'],
        ]);

        return true;
    });

    expect($match->fresh()->submitted_at)->not->toBeNull();
});

it('aggregates opponent cards across games and caps quantity at 4', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([
        [['mtgo_id' => 2001, 'quantity' => 3]],
        [['mtgo_id' => 2001, 'quantity' => 3]],
    ]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['opponent_deck'])->toBe([
            ['mtgo_id' => 2001, 'quantity' => 4, 'zone' => 'main'],
        ]);

        return true;
    });
});

it('sends a null league_run for non-league matches', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([
        [['mtgo_id' => 3001, 'quantity' => 1]],
    ]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['league_run'])->toBeNull();

        return true;
    });
});
