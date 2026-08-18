<?php

use App\Models\Card;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('includes log-only opponent casts in opponentCardsSeen', function () {
    // The opponent cast a card that later left every visible zone, so the
    // final GameCards snapshot (deck_json) misses it. The game log still has
    // the cast — the reveals rail must show it.
    Card::factory()->create(['mtgo_id' => 8001, 'name' => 'Swamp', 'type' => 'Basic Land — Swamp']);
    Card::factory()->create(['mtgo_id' => 8002, 'name' => 'Lembas', 'type' => 'Artifact']);

    $match = MtgoMatch::factory()->create(['state' => 'complete']);

    $game = Game::factory()->for($match, 'match')->create([
        'won' => true,
        'started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(5),
    ]);

    $local = Player::create(['username' => 'testplayer']);
    $opponent = Player::create(['username' => 'opponent']);

    $game->players()->attach($local->id, [
        'instance_id' => 0,
        'is_local' => true,
        'on_play' => true,
        'deck_json' => [],
    ]);
    $game->players()->attach($opponent->id, [
        'instance_id' => 1,
        'is_local' => false,
        'on_play' => false,
        'deck_json' => [
            ['mtgo_id' => 8001, 'quantity' => 2, 'sideboard' => false],
        ],
    ]);

    GameLog::create([
        'match_token' => $match->token,
        'file_path' => '/some/path.dat',
        'decoded_entries' => [
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Ptestplayer joined the game.'],
            ['timestamp' => '2026-01-01T00:00:00+00:00', 'message' => '@P@Popponent joined the game.'],
            ['timestamp' => '2026-01-01T00:00:01+00:00', 'message' => '@Popponent casts @[Lembas@:16004,100:@].'],
            ['timestamp' => '2026-01-01T00:00:02+00:00', 'message' => '@Ptestplayer wins the game.'],
        ],
    ]);

    $this->get(route('matches.show', $match->id))
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('matches/Show')
            ->where('games.0.opponentCardsSeen', fn ($cards) => collect($cards)->pluck('name')->contains('Lembas')
                && collect($cards)->pluck('name')->contains('Swamp'))
        );
});
