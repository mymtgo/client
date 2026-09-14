<?php

use App\Actions\Decks\GetDeckWinrateDelta;
use App\Actions\Util\TimeframeRange;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deltaDeck(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$deck, $version];
}

it('compares the current window against the one before it', function () {
    [$deck, $version] = deltaDeck();

    // Current week: 3 wins, 1 loss = 75%.
    MtgoMatch::factory()->count(3)->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);

    // Previous week: 1 win, 3 losses = 25%.
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);
    MtgoMatch::factory()->count(3)->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);

    [$start] = TimeframeRange::run('week');

    $delta = GetDeckWinrateDelta::run($deck, $start, 'week');

    expect($delta['previousRate'])->toBe(25)
        ->and($delta['previousTotal'])->toBe(4)
        ->and($delta['delta'])->toBe(50);
});

it('has nothing to compare an all-time window against', function () {
    [$deck, $version] = deltaDeck();

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);

    [$start] = TimeframeRange::run('alltime');

    $delta = GetDeckWinrateDelta::run($deck, $start, 'alltime');

    expect($delta['previousRate'])->toBeNull()
        ->and($delta['delta'])->toBeNull()
        ->and($delta['previousTotal'])->toBe(0);
});

it('reports no comparison when the previous window is empty', function () {
    [$deck, $version] = deltaDeck();

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);

    [$start] = TimeframeRange::run('week');

    $delta = GetDeckWinrateDelta::run($deck, $start, 'week');

    expect($delta['previousTotal'])->toBe(0)
        ->and($delta['previousRate'])->toBeNull()
        ->and($delta['delta'])->toBeNull();
});

it('narrows to a single deck version when one is given', function () {
    [$deck, $version] = deltaDeck();
    $other = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(2)]);
    MtgoMatch::factory()->count(2)->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subDays(10)]);
    MtgoMatch::factory()->count(4)->lost()->create(['deck_version_id' => $other->id, 'started_at' => now()->subDays(10)]);

    [$start] = TimeframeRange::run('week');

    $delta = GetDeckWinrateDelta::run($deck, $start, 'week', $version);

    expect($delta['previousTotal'])->toBe(2)
        ->and($delta['previousRate'])->toBe(100);
});
