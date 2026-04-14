<?php

use App\Actions\Dashboard\GetPlayDrawSplit;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupPlayDrawAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $player = Player::firstOrCreate(['username' => 'testplayer']);

    return [$account, $version, $player];
}

function createGameWithPlayer(MtgoMatch $match, Player $player, bool $won, bool $onPlay): Game
{
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => $match->started_at,
        'ended_at' => $match->started_at->addMinutes(10),
        'won' => $won,
    ]);
    $game->players()->attach($player, [
        'on_play' => $onPlay,
        'is_local' => true,
        'instance_id' => 1,
    ]);

    return $game;
}

it('returns zero winrates when no game data', function () {
    $result = GetPlayDrawSplit::run(null, now()->subWeek(), now());
    expect($result)->toBe(['otpWinrate' => 0, 'otdWinrate' => 0]);
});

it('calculates OTP and OTD winrates correctly', function () {
    [$account, $version, $player] = setupPlayDrawAccount();
    $match1 = MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    $match2 = MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    createGameWithPlayer($match1, $player, true, true);
    createGameWithPlayer($match2, $player, false, true);
    createGameWithPlayer($match1, $player, true, false);
    createGameWithPlayer($match2, $player, true, false);
    $result = GetPlayDrawSplit::run($account->id, now()->subWeek(), now());
    expect($result['otpWinrate'])->toBe(50);
    expect($result['otdWinrate'])->toBe(100);
});

it('ignores opponent player records', function () {
    [$account, $version, $player] = setupPlayDrawAccount();
    $opponent = Player::firstOrCreate(['username' => 'opponent']);
    $match = MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    $game = Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => $match->started_at,
        'ended_at' => $match->started_at->addMinutes(10),
        'won' => true,
    ]);
    $game->players()->attach($player, ['on_play' => true, 'is_local' => true, 'instance_id' => 1]);
    $game->players()->attach($opponent, ['on_play' => false, 'is_local' => false, 'instance_id' => 2]);
    $result = GetPlayDrawSplit::run($account->id, now()->subWeek(), now());
    expect($result['otpWinrate'])->toBe(100);
    expect($result['otdWinrate'])->toBe(0);
});
