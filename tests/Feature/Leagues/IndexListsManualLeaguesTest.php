<?php

use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'tester', 'active' => true]);
    Account::flushCurrent();
    $this->deck = Deck::factory()->create(['account_id' => $this->account->id, 'format' => 'CModern']);
    $this->version = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
});

it('lists a manual league with zero matches', function () {
    League::factory()->manual()->create([
        'name' => 'Empty manual league',
        'format' => 'CModern',
        'deck_version_id' => $this->version->id,
        'started_at' => now()->subHour(),
    ]);

    $response = $this->get('/leagues');

    $response->assertOk();
    $payload = $response->viewData('page')['props']['leagues']['data'];

    expect(collect($payload)->filter()->pluck('name'))->toContain('Empty manual league');
});

it('exposes manual deck options for the create dialog', function () {
    $other = Deck::factory()->create(['account_id' => $this->account->id, 'name' => 'Burn', 'format' => 'CModern']);

    $response = $this->get('/leagues');

    $manualDeckOptions = collect($response->viewData('page')['props']['manualDeckOptions']);
    expect($manualDeckOptions->pluck('id'))->toContain($this->deck->id, $other->id);
});
