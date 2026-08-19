<?php

use App\Actions\Matches\SubmitMatchToApi;
use App\Models\Archetype;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $factory = Http::getFacadeRoot();
    $ref = new ReflectionProperty($factory, 'stubCallbacks');
    $ref->setValue($factory, collect());
});

/**
 * Build a submittable match with one game, a local + opponent player, and
 * resolved player/opponent archetypes. Opponent's seen cards come from the
 * game_player.deck_json pivot.
 *
 * @param  array<int, array<int, array{mtgo_id: int, quantity: int}>>  $opponentDeckJsonPerGame
 */
function makeSubmittableMatch(array $opponentDeckJsonPerGame, ?League $league = null): MtgoMatch
{
    $deckVersion = DeckVersion::factory()->create();

    $match = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $deckVersion->id,
        'league_id' => $league?->id,
        'tournament_event_id' => null,
    ]);

    $local = Player::factory()->create();
    $opponent = Player::factory()->create();

    foreach ($opponentDeckJsonPerGame as $deckJson) {
        $game = Game::factory()->create(['match_id' => $match->id, 'won' => true]);

        $game->players()->attach($local->id, [
            'instance_id' => 2,
            'is_local' => true,
            'on_play' => true,
            'starting_hand_size' => 7,
            'deck_json' => [],
        ]);

        $game->players()->attach($opponent->id, [
            'instance_id' => 1,
            'is_local' => false,
            'on_play' => false,
            'starting_hand_size' => 7,
            'deck_json' => $deckJson,
        ]);
    }

    $playerArchetype = Archetype::factory()->create();
    $opponentArchetype = Archetype::factory()->create();

    $match->archetypes()->create([
        'player_id' => $local->id,
        'archetype_id' => $playerArchetype->id,
        'confidence' => 0.9,
    ]);

    $match->archetypes()->create([
        'player_id' => $opponent->id,
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

it('sends per-game turn count and timing', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([
        [['mtgo_id' => 4001, 'quantity' => 1]],
    ]);

    $match->games()->update([
        'turn_count' => 12,
        'started_at' => '2026-08-19 10:00:00',
        'ended_at' => '2026-08-19 10:14:30',
    ]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['games'][0]['turn_count'])->toBe(12);
        expect($request['games'][0]['started_at'])->toBe('2026-08-19T10:00:00+00:00');
        expect($request['games'][0]['ended_at'])->toBe('2026-08-19T10:14:30+00:00');

        return true;
    });
});

it('sends null turn count and ended_at for games that never recorded them', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([
        [['mtgo_id' => 4002, 'quantity' => 1]],
    ]);

    $match->games()->update([
        'turn_count' => null,
        'ended_at' => null,
    ]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['games'][0])->toHaveKeys(['turn_count', 'started_at', 'ended_at']);
        expect($request['games'][0]['turn_count'])->toBeNull();
        expect($request['games'][0]['ended_at'])->toBeNull();
        expect($request['games'][0]['started_at'])->not->toBeNull();

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
