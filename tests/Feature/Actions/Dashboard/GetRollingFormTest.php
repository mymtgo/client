<?php

use App\Actions\Dashboard\GetRollingForm;
use App\Enums\MatchOutcome;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupFormAccount(): array
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    return [$account, $version];
}

it('returns empty when no matches exist', function () {
    $result = GetRollingForm::run(null);
    expect($result)->toBe([
        'results' => [],
        'winrate' => 0,
        'allTimeWinrate' => 0,
        'delta' => 0,
    ]);
});

it('returns results for fewer than 20 matches', function () {
    [$account, $version] = setupFormAccount();
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHours(2)]);
    MtgoMatch::factory()->lost()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    $result = GetRollingForm::run($account->id);
    expect($result['results'])->toHaveCount(2);
    expect($result['results'])->toBe(['W', 'L']);
    expect($result['winrate'])->toBe(50);
});

it('returns last 20 matches in chronological order', function () {
    [$account, $version] = setupFormAccount();
    for ($i = 25; $i > 0; $i--) {
        MtgoMatch::factory()->create([
            'deck_version_id' => $version->id,
            'outcome' => $i % 2 === 0 ? MatchOutcome::Win : MatchOutcome::Loss,
            'started_at' => now()->subHours($i),
        ]);
    }
    $result = GetRollingForm::run($account->id);
    expect($result['results'])->toHaveCount(20);
});

it('excludes draws from winrate denominator', function () {
    [$account, $version] = setupFormAccount();
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHours(3)]);
    MtgoMatch::factory()->create([
        'deck_version_id' => $version->id,
        'outcome' => MatchOutcome::Draw,
        'started_at' => now()->subHours(2),
    ]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'started_at' => now()->subHour()]);
    $result = GetRollingForm::run($account->id);
    expect($result['results'])->toBe(['W', 'D', 'W']);
    expect($result['winrate'])->toBe(100);
});
