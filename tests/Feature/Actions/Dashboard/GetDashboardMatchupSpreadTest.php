<?php

use App\Actions\Dashboard\GetDashboardMatchupSpread;
use App\Models\Account;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupMatchupAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $opponent = Player::firstOrCreate(['username' => 'opponent']);

    return [$account, $version, $opponent];
}

it('returns empty array when no matches', function () {
    $result = GetDashboardMatchupSpread::run(null, now()->subWeek(), now());
    expect($result)->toBeEmpty();
});

it('returns top 5 opponent archetypes by match count', function () {
    [$account, $version, $opponent] = setupMatchupAccount();
    $archetype = Archetype::factory()->create(['name' => 'Burn']);
    $match = MtgoMatch::factory()->won()->create([
        'deck_version_id' => $version->id,
        'started_at' => now()->subDay(),
    ]);

    // MUST create game + game_player with is_local=0 for whereExists
    $game = \App\Models\Game::create([
        'match_id' => $match->id,
        'mtgo_id' => fake()->unique()->randomNumber(8),
        'started_at' => $match->started_at,
        'ended_at' => $match->started_at->addMinutes(10),
        'won' => true,
    ]);
    $game->players()->attach($opponent, ['on_play' => false, 'is_local' => false, 'instance_id' => 2]);

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $archetype->id,
        'player_id' => $opponent->id,
        'confidence' => 1.0,
    ]);

    $result = GetDashboardMatchupSpread::run($account->id, now()->subWeek(), now());
    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('Burn');
    expect($result[0]['winrate'])->toBe(100);
    expect($result[0]['wins'])->toBe(1);
    expect($result[0]['losses'])->toBe(0);
});

it('limits to top 5 results', function () {
    [$account, $version, $opponent] = setupMatchupAccount();
    for ($i = 0; $i < 7; $i++) {
        $archetype = Archetype::factory()->create(['name' => "Archetype {$i}"]);
        $match = MtgoMatch::factory()->won()->create([
            'deck_version_id' => $version->id,
            'started_at' => now()->subDay(),
        ]);
        $game = \App\Models\Game::create([
            'match_id' => $match->id,
            'mtgo_id' => fake()->unique()->randomNumber(8),
            'started_at' => $match->started_at,
            'ended_at' => $match->started_at->addMinutes(10),
            'won' => true,
        ]);
        $game->players()->attach($opponent, ['on_play' => false, 'is_local' => false, 'instance_id' => 2]);
        MatchArchetype::create([
            'mtgo_match_id' => $match->id,
            'archetype_id' => $archetype->id,
            'player_id' => $opponent->id,
            'confidence' => 1.0,
        ]);
    }
    $result = GetDashboardMatchupSpread::run($account->id, now()->subWeek(), now());
    expect($result)->toHaveCount(5);
});
