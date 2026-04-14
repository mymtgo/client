<?php

use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupLeagueData(int $count = 25): void
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    for ($i = 0; $i < $count; $i++) {
        $league = League::factory()->create([
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays($i),
            'state' => 'complete',
        ]);
        MtgoMatch::factory()->won()->create([
            'league_id' => $league->id,
            'deck_version_id' => $version->id,
            'started_at' => now()->subDays($i),
        ]);
    }
}

it('paginates leagues server-side', function () {
    setupLeagueData(25);

    $response = $this->get('/leagues')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['leagues'])->toHaveKey('data');
    expect(count($props['leagues']['data']))->toBeLessThanOrEqual(20);
});

it('filters leagues by format', function () {
    setupLeagueData(5);

    $this->get('/leagues?format=Standard')->assertOk();
});

it('returns format options and filters', function () {
    setupLeagueData(3);

    $response = $this->get('/leagues')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['allFormats'])->toBeArray();
    expect($props['filters'])->toHaveKeys(['format']);
});
