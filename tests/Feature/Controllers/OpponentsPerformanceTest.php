<?php

use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupOpponentsData(int $opponentCount = 30): void
{
    $account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    $deck = Deck::factory()->create(['account_id' => $account->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    for ($i = 0; $i < $opponentCount; $i++) {
        $opponent = Opponent::factory()->create(['username' => "opponent_{$i}"]);
        MtgoMatch::factory()->won()->create([
            'deck_version_id' => $version->id,
            'opponent_id' => $opponent->id,
            'started_at' => now()->subHours($i),
        ]);
    }
}

it('paginates opponents server-side', function () {
    setupOpponentsData(30);

    $response = $this->get('/opponents')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['opponents'])->toHaveKey('data');
    expect(count($props['opponents']['data']))->toBe(25);
});

it('filters opponents by search', function () {
    setupOpponentsData(5);

    $response = $this->get('/opponents?search=opponent_0')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect(count($props['opponents']['data']))->toBe(1);
    expect($props['opponents']['data'][0]['username'])->toBe('opponent_0');
});

it('supports sort parameter', function () {
    setupOpponentsData(5);

    $this->get('/opponents?sort=most_recent')->assertOk();
    $this->get('/opponents?sort=winrate_desc')->assertOk();
    $this->get('/opponents?sort=most_played')->assertOk();
});

it('returns format options', function () {
    setupOpponentsData(3);

    $response = $this->get('/opponents')->assertOk();
    $props = $response->original->getData()['page']['props'];

    expect($props['allFormats'])->toBeArray();
    expect($props['filters'])->toHaveKeys(['search', 'sort', 'format']);
});
