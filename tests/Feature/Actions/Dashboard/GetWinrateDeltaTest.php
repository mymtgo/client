<?php

use App\Actions\Dashboard\GetWinrateDelta;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupDeltaAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

it('returns zero when no matches in either period', function () {
    $result = GetWinrateDelta::run(null, now()->subDays(7)->startOfDay(), now()->endOfDay(), 'week');
    expect($result)->toBe(['matchDelta' => 0, 'gameDelta' => 0]);
});

it('returns positive delta when current period is better', function () {
    [$account, $version] = setupDeltaAccount();

    // Previous week: 1W 1L (50%)
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(9)]);

    // Current week: 3W 1L (75%)
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);

    $result = GetWinrateDelta::run($account->id, now()->subDays(7)->startOfDay(), now()->endOfDay(), 'week');
    expect($result['matchDelta'])->toBe(25);
});

it('returns negative delta when current period is worse', function () {
    [$account, $version] = setupDeltaAccount();

    // Previous week: 3W 1L (75%)
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(9)]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(9)]);

    // Current week: 1W 3L (25%)
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);

    $result = GetWinrateDelta::run($account->id, now()->subDays(7)->startOfDay(), now()->endOfDay(), 'week');
    expect($result['matchDelta'])->toBe(-50);
});
