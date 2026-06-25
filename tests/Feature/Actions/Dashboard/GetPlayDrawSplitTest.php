<?php

use App\Actions\Dashboard\GetPlayDrawSplit;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupPlayDrawAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

function createGameWithLocalOnPlay(MtgoMatch $match, bool $won, bool $localOnPlay): Game
{
    return Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => $match->started_at,
        'ended_at' => $match->started_at->addMinutes(10),
        'won' => $won,
        'local_on_play' => $localOnPlay,
    ]);
}

it('returns zero winrates when no game data', function () {
    $result = GetPlayDrawSplit::run(null, now()->subWeek(), now());
    expect($result)->toBe(['otpWinrate' => 0, 'otdWinrate' => 0]);
});

it('calculates OTP and OTD winrates correctly', function () {
    [$account, $version] = setupPlayDrawAccount();
    $match1 = MtgoMatch::factory()->won()->create(['account_id' => $account->id, 'deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    $match2 = MtgoMatch::factory()->lost()->create(['account_id' => $account->id, 'deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    createGameWithLocalOnPlay($match1, true, true);   // OTP win
    createGameWithLocalOnPlay($match2, false, true);  // OTP loss
    createGameWithLocalOnPlay($match1, true, false);  // OTD win
    createGameWithLocalOnPlay($match2, true, false);  // OTD win
    $result = GetPlayDrawSplit::run($account->id, now()->subWeek(), now());
    expect($result['otpWinrate'])->toBe(50);
    expect($result['otdWinrate'])->toBe(100);
});

it('only reads local_on_play column (no game_player join)', function () {
    [$account, $version] = setupPlayDrawAccount();
    $match = MtgoMatch::factory()->won()->create(['account_id' => $account->id, 'deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    createGameWithLocalOnPlay($match, true, true);
    $result = GetPlayDrawSplit::run($account->id, now()->subWeek(), now());
    expect($result['otpWinrate'])->toBe(100);
    expect($result['otdWinrate'])->toBe(0);
});
