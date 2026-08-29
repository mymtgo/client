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
use Illuminate\Support\Str;

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

it('sends the client version with the report', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    config()->set('nativephp.version', '0.29.0');

    $match = makeSubmittableMatch([[['mtgo_id' => 1001, 'quantity' => 1]]]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['client_version'])->toBe('0.29.0');

        return true;
    });
});

it('never reports a limited match', function (): void {
    Http::fake(['*' => Http::response([])]);

    $match = makeSubmittableMatch([[['mtgo_id' => 1001, 'quantity' => 1]]]);
    $match->update(['format' => 'DHOBHOBHOB']);

    SubmitMatchToApi::run($match->id);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/matches/report'));
    expect($match->fresh()->submitted_at)->toBeNull();
});

it('leaves limited matches out of the submittable queue', function (): void {
    $limited = makeSubmittableMatch([[['mtgo_id' => 1001, 'quantity' => 1]]]);
    $limited->update(['format' => 'DHOBHOBHOB']);

    $constructed = makeSubmittableMatch([[['mtgo_id' => 1001, 'quantity' => 1]]]);

    expect(MtgoMatch::submittable()->pluck('id')->all())->toBe([$constructed->id]);
});

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

/**
 * A locally created archetype's uuid is `<8-char device id>-<uuid>`
 * (StoreManualArchetype), which the API's `nullable|uuid` rule rejects — so
 * sending it 422s the whole report and the match retries forever. The API
 * cannot resolve a client-local uuid anyway, and re-derives the seat from the
 * deck it was sent, so the seat goes over as null.
 */
it('sends a null opponent archetype uuid when the opponent archetype is local to this client', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([[['mtgo_id' => 3001, 'quantity' => 1]]]);

    $local = Archetype::factory()->manual()->create([
        'uuid' => 'a1b2c3d4-'.Str::uuid(),
    ]);

    $match->opponentArchetypes()->first()->update(['archetype_id' => $local->id]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['opponent_archetype_uuid'])->toBeNull();

        return true;
    });

    expect($match->fresh()->submitted_at)->not->toBeNull();
});

it('sends a null player archetype uuid when the player archetype is local to this client', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([[['mtgo_id' => 3002, 'quantity' => 1]]]);

    $local = Archetype::factory()->manual()->create([
        'uuid' => 'b2c3d4e5-'.Str::uuid(),
    ]);

    $opponentPlayerIds = $match->opponentArchetypes()->pluck('player_id')->all();

    $match->archetypes()
        ->whereNotIn('player_id', $opponentPlayerIds)
        ->first()
        ->update(['archetype_id' => $local->id]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['player_archetype_uuid'])->toBeNull();

        return true;
    });

    expect($match->fresh()->submitted_at)->not->toBeNull();
});

/**
 * Homebrew and Rogue are client-side seeds the API has no row for, so their
 * sentinel uuids store as a permanently dangling seat and drop the match from
 * every matchup the API builds.
 */
it('sends a null opponent archetype uuid for the fallback archetypes', function (): void {
    Http::fake([
        '*/api/matches/report' => Http::response([], 200),
        '*' => Http::response([]),
    ]);

    $match = makeSubmittableMatch([[['mtgo_id' => 3003, 'quantity' => 1]]]);

    $homebrew = Archetype::query()
        ->where('uuid', '00000000-0000-0000-0000-000000000001')
        ->sole();

    $match->opponentArchetypes()->first()->update(['archetype_id' => $homebrew->id]);

    SubmitMatchToApi::run($match->id);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/matches/report')) {
            return false;
        }

        expect($request['opponent_archetype_uuid'])->toBeNull();

        return true;
    });
});
