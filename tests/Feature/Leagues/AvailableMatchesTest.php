<?php

use App\Enums\MatchState;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create(['username' => 'tester', 'active' => true]);
    Account::flushCurrent();
    $this->deck = Deck::factory()->create(['account_id' => $this->account->id]);
    $this->version = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
    $this->league = League::factory()->manual()->create(['deck_version_id' => $this->version->id]);
});

it('returns unlinked complete matches for the same deck', function () {
    $candidate = MtgoMatch::factory()->create([
        'deck_version_id' => $this->version->id,
        'league_id' => null,
        'state' => MatchState::Complete,
    ]);

    $response = $this->getJson("/leagues/{$this->league->id}/available-matches");

    $response->assertOk();
    expect(collect($response->json())->pluck('id'))->toContain($candidate->id);
});

it('excludes matches already linked to a league', function () {
    $other = League::factory()->manual()->create(['deck_version_id' => $this->version->id]);
    $linked = MtgoMatch::factory()->create([
        'deck_version_id' => $this->version->id,
        'league_id' => $other->id,
    ]);

    $response = $this->getJson("/leagues/{$this->league->id}/available-matches");

    expect(collect($response->json())->pluck('id'))->not->toContain($linked->id);
});

it('excludes matches from a different deck', function () {
    $otherDeck = Deck::factory()->create(['account_id' => $this->account->id]);
    $otherVersion = DeckVersion::factory()->create(['deck_id' => $otherDeck->id]);
    $foreign = MtgoMatch::factory()->create([
        'deck_version_id' => $otherVersion->id,
        'league_id' => null,
    ]);

    $response = $this->getJson("/leagues/{$this->league->id}/available-matches");

    expect(collect($response->json())->pluck('id'))->not->toContain($foreign->id);
});

it('rejects non-manual leagues', function () {
    $auto = League::factory()->create(['manual' => false, 'deck_version_id' => $this->version->id]);

    $this->getJson("/leagues/{$auto->id}/available-matches")
        ->assertStatus(422);
});
