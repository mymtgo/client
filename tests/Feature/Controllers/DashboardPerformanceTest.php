<?php

use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setupDashboardData(): void
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $player = Player::firstOrCreate(['username' => 'testplayer']);

    for ($i = 0; $i < 5; $i++) {
        $match = MtgoMatch::factory()->won()->create([
            'deck_version_id' => $version->id,
            'started_at' => now()->subHours($i),
        ]);
        $game = Game::create([
            'match_id' => $match->id,
            'mtgo_id' => fake()->unique()->randomNumber(8),
            'started_at' => $match->started_at,
            'won' => true,
        ]);
        $game->players()->attach($player, [
            'on_play' => $i % 2 === 0,
            'is_local' => true,
            'instance_id' => 1,
        ]);
    }
}

it('loads dashboard with bounded query count', function () {
    setupDashboardData();

    DB::enableQueryLog();
    $response = $this->get('/');
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    // Consolidated match+game stats into single joined query (saves ~3 queries).
    // Deferred props are excluded on initial load. Remaining eager queries:
    // account, formats, stats, deck stats, winrate delta, active league, streak, play/draw split.
    expect($queryCount)->toBeLessThan(27);
});

it('returns correct match and game stats', function () {
    setupDashboardData();

    $response = $this->get('/')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['matchesWon'])->toBe(5);
    expect($props['matchesLost'])->toBe(0);
    expect($props['gamesWon'])->toBe(5);
    expect($props['gamesLost'])->toBe(0);
});
