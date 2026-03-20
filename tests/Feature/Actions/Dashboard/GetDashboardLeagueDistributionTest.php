<?php

use App\Actions\Dashboard\GetDashboardLeagueDistribution;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupLeagueDistAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

function createLeagueWithRecord(DeckVersion $version, int $wins, int $losses): void
{
    $league = League::factory()->complete()->create(['deck_version_id' => $version->id]);
    for ($i = 0; $i < $wins; $i++) {
        MtgoMatch::factory()->won()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays(rand(1, 30)),
        ]);
    }
    for ($i = 0; $i < $losses; $i++) {
        MtgoMatch::factory()->lost()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays(rand(1, 30)),
        ]);
    }
}

it('returns empty buckets when no leagues', function () {
    $result = GetDashboardLeagueDistribution::run(null);
    expect($result['buckets'])->toBe(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0]);
    expect($result['trophies'])->toBe(0);
    expect($result['total'])->toBe(0);
});

it('counts league results in correct buckets', function () {
    [$account, $version] = setupLeagueDistAccount();
    createLeagueWithRecord($version, 5, 0);
    createLeagueWithRecord($version, 4, 1);
    createLeagueWithRecord($version, 3, 2);
    $result = GetDashboardLeagueDistribution::run($account->id);
    expect($result['buckets']['5-0'])->toBe(1);
    expect($result['buckets']['4-1'])->toBe(1);
    expect($result['buckets']['3-2'])->toBe(1);
    expect($result['trophies'])->toBe(1);
    expect($result['total'])->toBe(3);
});

it('excludes phantom leagues', function () {
    [$account, $version] = setupLeagueDistAccount();
    createLeagueWithRecord($version, 5, 0);
    $phantom = League::factory()->phantom()->complete()->create(['deck_version_id' => $version->id]);
    for ($i = 0; $i < 5; $i++) {
        MtgoMatch::factory()->won()->create([
            'league_id' => $phantom->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDay(),
        ]);
    }
    $result = GetDashboardLeagueDistribution::run($account->id);
    expect($result['total'])->toBe(1);
});
