<?php

use App\Actions\Dashboard\GetWinrateDelta;
use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;
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

it('bounds the previous period on local midnight in the system timezone', function () {
    AppSettings::setSystemTimezone('America/Los_Angeles');
    [$account, $version] = setupDeltaAccount();

    // Current window: 2 Sep 00:00 PDT to 9 Sep 23:59:59 PDT, as UTC.
    $currentStart = Carbon::parse('2026-09-02 07:00:00', 'UTC');
    $currentEnd = Carbon::parse('2026-09-10 06:59:59', 'UTC');

    // Current: 1W 1L (50%).
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => Carbon::parse('2026-09-05 12:00:00', 'UTC')]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => Carbon::parse('2026-09-06 12:00:00', 'UTC')]);

    // Previous window mirrors the current one: 25 Aug 00:00 PDT to 1 Sep 23:59:59 PDT.
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => Carbon::parse('2026-08-27 12:00:00', 'UTC')]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => Carbon::parse('2026-08-28 12:00:00', 'UTC')]);
    // 12:00 UTC on 25 Aug is 05:00 PDT on 25 Aug: inside the local previous window,
    // but before a UTC-midnight window that would start at 26 Aug 00:00 UTC.
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => Carbon::parse('2026-08-25 12:00:00', 'UTC')]);

    $result = GetWinrateDelta::run($account->id, $currentStart, $currentEnd, 'week');

    // Previous 1W 2L (33%), current 50%.
    expect($result['matchDelta'])->toBe(17);
});

it('counts draws as matches played in the match winrate', function () {
    [$account, $version] = setupDeltaAccount();

    // Current: 1W 1D = 50%, not 100%. Nothing in the previous period.
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDay()]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Draw,
        'started_at' => now()->subDay(),
    ]);

    $result = GetWinrateDelta::run($account->id, now()->subDays(7)->startOfDay(), now()->endOfDay(), 'week');

    expect($result['matchDelta'])->toBe(50);
});
