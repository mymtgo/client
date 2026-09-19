<?php

use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\Widgets\DeckPerformanceWidget;
use App\Dashboard\Widgets\LeagueResultsWidget;
use App\Dashboard\Widgets\RecentMatchesWidget;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $this->deck = Deck::factory()->create(['account_id' => $account->id, 'name' => 'Tron']);
    $version = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
    $match = MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    Game::factory()->create(['match_id' => $match->id, 'won' => true]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subYears(2)]);
});

it('lists decks played in the timeframe with their record', function () {
    $decks = (new DeckPerformanceWidget)->resolve([], DashboardScope::fromTimeframe('monthly'));

    expect($decks)->toHaveCount(1)
        ->and($decks->first()->name)->toBe('Tron')
        ->and($decks->first()->record->wins)->toBe(1)
        ->and($decks->first()->record->losses)->toBe(0);
});

it('lists recent matches inside the timeframe only', function () {
    $matches = (new RecentMatchesWidget)->resolve([], DashboardScope::fromTimeframe('monthly'));

    expect($matches)->toHaveCount(1);
});

it('normalises the league results format config', function () {
    $widget = new LeagueResultsWidget;

    expect($widget->validateConfig([]))->toBe(['format' => null])
        ->and($widget->validateConfig(['format' => '']))->toBe(['format' => null])
        ->and($widget->validateConfig(['format' => 'CModern']))->toBe(['format' => 'CModern'])
        ->and(fn () => $widget->validateConfig(['format' => 1]))->toThrow(InvalidWidgetConfig::class);
});

it('labels league results with the chosen format', function () {
    $all = (new LeagueResultsWidget)->resolve(['format' => null], DashboardScope::fromTimeframe('alltime'));
    $modern = (new LeagueResultsWidget)->resolve(['format' => 'CModern'], DashboardScope::fromTimeframe('alltime'));

    expect($all['formatLabel'])->toBeNull()
        ->and($modern['formatLabel'])->toBe('Modern')
        ->and($modern)->toHaveKeys(['buckets', 'trophies', 'total']);
});
