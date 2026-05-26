<?php

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

it('unlinks a match from a manual league', function () {
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $this->version->id,
        'league_id' => $this->league->id,
    ]);

    $this->delete("/leagues/{$this->league->id}/matches/{$match->id}")
        ->assertRedirect();

    expect($match->fresh()->league_id)->toBeNull();
});

it('rejects when match belongs to a different league', function () {
    $other = League::factory()->manual()->create(['deck_version_id' => $this->version->id]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $this->version->id,
        'league_id' => $other->id,
    ]);

    $this->delete("/leagues/{$this->league->id}/matches/{$match->id}")
        ->assertSessionHasErrors('match');

    expect($match->fresh()->league_id)->toBe($other->id);
});

it('rejects non-manual leagues', function () {
    $auto = League::factory()->create(['manual' => false, 'deck_version_id' => $this->version->id]);
    $match = MtgoMatch::factory()->create([
        'deck_version_id' => $this->version->id,
        'league_id' => $auto->id,
    ]);

    $this->delete("/leagues/{$auto->id}/matches/{$match->id}")
        ->assertSessionHasErrors('match');

    expect($match->fresh()->league_id)->toBe($auto->id);
});
