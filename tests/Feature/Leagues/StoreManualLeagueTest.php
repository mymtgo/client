<?php

use App\Enums\LeagueState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'testplayer', 'active' => true, 'tracked' => true]);
    Account::flushCurrent();
    $this->deck = Deck::factory()->create([
        'account_id' => $this->account->id,
        'format' => 'CModern',
    ]);
    $this->latestVersion = DeckVersion::factory()->create([
        'deck_id' => $this->deck->id,
        'modified_at' => now()->subDay(),
    ]);
    DeckVersion::factory()->create([
        'deck_id' => $this->deck->id,
        'modified_at' => now()->subDays(5),
    ]);
});

it('creates a complete manual league bound to the latest deck version', function () {
    $response = $this->post('/leagues', [
        'deck_id' => $this->deck->id,
        'started_at' => now()->subHour()->toIso8601String(),
        'name' => 'Modern League 26-05-2026 08:00pm',
    ]);

    $response->assertRedirect();

    $league = League::query()->latest('id')->first();
    expect($league)->not->toBeNull()
        ->and($league->manual)->toBeTrue()
        ->and($league->state)->toBe(LeagueState::Complete)
        ->and($league->completed_at)->not->toBeNull()
        ->and($league->format)->toBe('CModern')
        ->and($league->deck_version_id)->toBe($this->latestVersion->id)
        ->and($league->token)->toStartWith('manual_')
        ->and($league->name)->toBe('Modern League 26-05-2026 08:00pm');
});

it('rejects decks not owned by the active account', function () {
    $otherAccount = Account::create(['username' => 'otherplayer', 'active' => false, 'tracked' => false]);
    Account::flushCurrent();
    $otherDeck = Deck::factory()->create(['account_id' => $otherAccount->id]);
    DeckVersion::factory()->create(['deck_id' => $otherDeck->id]);

    $this->post('/leagues', [
        'deck_id' => $otherDeck->id,
        'started_at' => now()->subHour()->toIso8601String(),
        'name' => 'Foreign deck league',
    ])->assertSessionHasErrors('deck_id');

    expect(League::count())->toBe(0);
});

it('rejects future started_at', function () {
    $this->post('/leagues', [
        'deck_id' => $this->deck->id,
        'started_at' => now()->addDay()->toIso8601String(),
        'name' => 'Future',
    ])->assertSessionHasErrors('started_at');
});
