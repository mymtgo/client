<?php

use App\Actions\Decks\GetDeckStats;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setupDeckStatsData(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $player = Player::firstOrCreate(['username' => 'testplayer']);

    return [$deck, $version, $player];
}

it('computes deck stats with bounded query count', function () {
    [$deck, $version, $player] = setupDeckStatsData();

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

    DB::enableQueryLog();
    $result = GetDeckStats::run($deck, now()->subWeek(), now());
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($result['wins'])->toBe(5);
    expect($result['losses'])->toBe(0);
    expect($result['gamesWon'])->toBe(5);
    expect($result['gamesLost'])->toBe(0);
    expect($result['otpWon'])->toBe(3); // i=0,2,4 are on_play=true, all won
    expect($result['otdWon'])->toBe(2); // i=1,3 are on_play=false, all won
    // Was 10+ queries, target <=6
    expect($queryCount)->toBeLessThan(7);
});

it('handles empty deck gracefully', function () {
    [$deck, $version, $player] = setupDeckStatsData();

    $result = GetDeckStats::run($deck, now()->subWeek(), now());

    expect($result['wins'])->toBe(0);
    expect($result['losses'])->toBe(0);
    expect($result['gamesWon'])->toBe(0);
    expect($result['gamesLost'])->toBe(0);
    expect($result['trophies'])->toBe(0);
});
