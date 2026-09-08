<?php

use App\Actions\Decks\GetDeckVersionStats;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes gameless imported matches in match and game counts', function () {
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $player = Player::firstOrCreate(['username' => 'testplayer']);

    // Tracked match with a real game row.
    $tracked = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHour(),
    ]);
    $game = Game::create([
        'match_id' => $tracked->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => $tracked->started_at,
        'won' => true,
    ]);
    $game->players()->attach($player, ['on_play' => true, 'is_local' => true, 'instance_id' => 1]);

    // Gameless imports — match-level tallies only.
    MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(2),
        'imported' => true,
        'games_won' => 2,
        'games_lost' => 1,
    ]);
    MtgoMatch::factory()->lost()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subHours(3),
        'imported' => true,
        'games_won' => 0,
        'games_lost' => 2,
    ]);

    $result = GetDeckVersionStats::run($deck, now()->subWeek(), now());
    $allVersions = $result[0]; // "All versions" aggregate row

    expect($allVersions['matchRecord']->wins)->toBe(2);
    expect($allVersions['matchRecord']->losses)->toBe(1);
    // 1 tracked game row won + match-level (2+0 won, 1+2 lost).
    expect($allVersions['gamesWon'])->toBe(3);
    expect($allVersions['gamesLost'])->toBe(3);
});

it('counts draws as matches played in the match winrate', function () {
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHours(2)]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Draw,
        'started_at' => now()->subHours(3),
    ]);

    $result = GetDeckVersionStats::run($deck, now()->subWeek(), now());

    // 1 / 3 for both the aggregate row and the single version row.
    expect($result[0]['matchRecord']->draws)->toBe(1);
    expect($result[0]['matchRecord']->winrate)->toBe(33);
    expect($result[1]['matchRecord']->draws)->toBe(1);
    expect($result[1]['matchRecord']->winrate)->toBe(33);
});
