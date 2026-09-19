<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\Widgets\DeckStatsWidget;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('validates the deck id shape', function () {
    $widget = new DeckStatsWidget;

    expect($widget->validateConfig(['deck_id' => 5]))->toBe(['deck_id' => 5])
        ->and($widget->validateConfig(['deck_id' => '5']))->toBe(['deck_id' => 5])
        ->and(fn () => $widget->validateConfig([]))->toThrow(InvalidWidgetConfig::class)
        ->and(fn () => $widget->validateConfig(['deck_id' => 'x']))->toThrow(InvalidWidgetConfig::class);
});

it('returns the deck record, game winrate and latest league for the timeframe', function () {
    $account = Account::create(['username' => 'me', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id, 'name' => 'Tron']);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    $league = League::factory()->complete()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);

    $win = MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'league_id' => $league->id, 'started_at' => now()->subHour()]);
    Game::factory()->count(2)->create(['match_id' => $win->id, 'won' => true]);
    $loss = MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'league_id' => $league->id, 'started_at' => now()->subHours(2)]);
    Game::factory()->create(['match_id' => $loss->id, 'won' => false]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subYears(2)]);

    $data = (new DeckStatsWidget)->resolve(['deck_id' => $deck->id], DashboardScope::fromTimeframe('monthly'));

    expect($data['deck']['name'])->toBe('Tron')
        ->and($data['matchRecord']->wins)->toBe(1)
        ->and($data['matchRecord']->losses)->toBe(1)
        ->and($data['gamesWon'])->toBe(2)
        ->and($data['gamesLost'])->toBe(1)
        ->and($data['latestLeague']['id'])->toBe($league->id);
});

it('returns null when the deck no longer exists', function () {
    expect((new DeckStatsWidget)->resolve(['deck_id' => 42], DashboardScope::fromTimeframe('alltime')))->toBeNull();
});
