<?php

use App\Actions\Dashboard\GetDashboardLeagueDistribution;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function setupLeagueDistAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

function createLeagueWithRecord(DeckVersion $version, int $wins, int $losses, ?Carbon $finishedAt = null): void
{
    $league = League::factory()->complete()->create(['deck_version_id' => $version->id]);
    $playedAt = $finishedAt ?? now()->subDays(rand(1, 30));

    for ($i = 0; $i < $wins; $i++) {
        MtgoMatch::factory()->won()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => $playedAt,
        ]);
    }
    for ($i = 0; $i < $losses; $i++) {
        MtgoMatch::factory()->lost()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => $playedAt,
        ]);
    }
}

it('returns empty buckets when no leagues', function () {
    $result = GetDashboardLeagueDistribution::run(null, now()->subCentury(), now()->endOfDay());
    expect($result['buckets'])->toBe(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0]);
    expect($result['trophies'])->toBe(0);
    expect($result['total'])->toBe(0);
});

it('counts league results in correct buckets', function () {
    [$account, $version] = setupLeagueDistAccount();
    createLeagueWithRecord($version, 5, 0);
    createLeagueWithRecord($version, 4, 1);
    createLeagueWithRecord($version, 3, 2);
    $result = GetDashboardLeagueDistribution::run($account->id, now()->subCentury(), now()->endOfDay());
    expect($result['buckets']['5-0'])->toBe(1);
    expect($result['buckets']['4-1'])->toBe(1);
    expect($result['buckets']['3-2'])->toBe(1);
    expect($result['trophies'])->toBe(1);
    expect($result['total'])->toBe(3);
});

/**
 * The dashboard's other cards all take the selected window. This one did not,
 * so every timeframe showed the same all-time distribution.
 */
it('counts only leagues finished inside the window', function () {
    [$account, $version] = setupLeagueDistAccount();

    createLeagueWithRecord($version, 5, 0, finishedAt: now()->subDays(3));
    createLeagueWithRecord($version, 3, 2, finishedAt: now()->subDays(40));

    $result = GetDashboardLeagueDistribution::run(
        $account->id,
        now()->subWeeks(2),
        now()->endOfDay(),
    );

    expect($result['buckets']['5-0'])->toBe(1);
    expect($result['buckets']['3-2'])->toBe(0);
    expect($result['total'])->toBe(1);
});

it('counts every match of a league that straddles the window edge', function () {
    // A league played across the boundary still has one record. Counting only
    // the matches inside the window would report it as 3-0, which is not a
    // league result at all.
    [$account, $version] = setupLeagueDistAccount();

    $league = League::factory()->complete()->create(['deck_version_id' => $version->id]);

    foreach ([16, 15, 15] as $daysAgo) {
        MtgoMatch::factory()->won()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays($daysAgo),
        ]);
    }
    foreach ([13, 13] as $daysAgo) {
        MtgoMatch::factory()->lost()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays($daysAgo),
        ]);
    }

    $result = GetDashboardLeagueDistribution::run(
        $account->id,
        now()->subWeeks(2),
        now()->endOfDay(),
    );

    expect($result['buckets']['3-2'])->toBe(1);
    expect($result['total'])->toBe(1);
});
