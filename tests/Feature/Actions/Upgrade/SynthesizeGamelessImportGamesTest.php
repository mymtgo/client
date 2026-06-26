<?php

use App\Actions\Upgrade\SynthesizeGamelessImportGames;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// (a) Synthesis for gameless matches
// ---------------------------------------------------------------------------

it('synthesizes games for a match with games_won and games_lost but no game rows', function () {
    $match = MtgoMatch::factory()->create([
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    SynthesizeGamelessImportGames::run();

    $games = Game::where('match_id', $match->id)->get();

    expect($games)->toHaveCount(3);
    expect($games->where('won', true)->count())->toBe(2);
    expect($games->where('won', false)->count())->toBe(1);
});

it('links synthesized games to the correct match', function () {
    $match = MtgoMatch::factory()->create([
        'games_won' => 1,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    SynthesizeGamelessImportGames::run();

    expect(Game::where('match_id', $match->id)->count())->toBe(2);
});

it('uses the match started_at for synthesized game started_at', function () {
    $startedAt = now()->subHour();

    $match = MtgoMatch::factory()->create([
        'games_won' => 1,
        'games_lost' => 0,
        'started_at' => $startedAt,
    ]);

    SynthesizeGamelessImportGames::run();

    $game = Game::where('match_id', $match->id)->first();

    expect($game->started_at->toDateTimeString())->toBe($startedAt->toDateTimeString());
});

it('skips a match that already has game rows (idempotent — synthesize step)', function () {
    $match = MtgoMatch::factory()->create([
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    // Pre-create one game: this match already has games, so synthesis must skip it.
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);

    SynthesizeGamelessImportGames::run();

    // Still exactly 1 game — the pre-existing one; nothing added.
    expect(Game::where('match_id', $match->id)->count())->toBe(1);
});

it('does not synthesize games for a match with no wins or losses', function () {
    $match = MtgoMatch::factory()->create([
        'games_won' => 0,
        'games_lost' => 0,
        'started_at' => now(),
    ]);

    SynthesizeGamelessImportGames::run();

    expect(Game::where('match_id', $match->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// (c) Idempotency — running twice produces the same result
// ---------------------------------------------------------------------------

it('is fully idempotent — running twice does not create duplicate synthetic games', function () {
    $match = MtgoMatch::factory()->create([
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    SynthesizeGamelessImportGames::run();
    SynthesizeGamelessImportGames::run();

    expect(Game::where('match_id', $match->id)->count())->toBe(3);
});

it('handles a mix of gameless matches and matches that already have games', function () {
    $gameless = MtgoMatch::factory()->create([
        'games_won' => 1,
        'games_lost' => 1,
        'started_at' => now(),
    ]);

    $withGames = MtgoMatch::factory()->create([
        'games_won' => 2,
        'games_lost' => 1,
        'started_at' => now(),
    ]);
    Game::factory()->create(['match_id' => $withGames->id, 'won' => true]);
    Game::factory()->create(['match_id' => $withGames->id, 'won' => true]);
    Game::factory()->create(['match_id' => $withGames->id, 'won' => false]);

    SynthesizeGamelessImportGames::run();

    // Gameless match gets synthesized.
    expect(Game::where('match_id', $gameless->id)->count())->toBe(2);
    // The match with existing games is untouched.
    expect(Game::where('match_id', $withGames->id)->count())->toBe(3);
});
