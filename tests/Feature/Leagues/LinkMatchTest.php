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
    $this->deck = Deck::factory()->create(['account_id' => $this->account->id, 'format' => 'CModern']);
    $this->version = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
    $this->league = League::factory()->manual()->create([
        'deck_version_id' => $this->version->id,
        'format' => 'CModern',
    ]);
});

function makeLeagueMatch(int $deckVersionId, array $overrides = []): MtgoMatch
{
    return MtgoMatch::factory()->create(array_merge([
        'deck_version_id' => $deckVersionId,
        'league_id' => null,
        'state' => MatchState::Complete,
    ], $overrides));
}

it('links an unlinked match of the same deck', function () {
    $match = makeLeagueMatch($this->version->id);

    $response = $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $match->id]);

    $response->assertRedirect();
    expect($match->fresh()->league_id)->toBe($this->league->id);
});

it('accepts a match from a different version of the same deck', function () {
    $olderVersion = DeckVersion::factory()->create(['deck_id' => $this->deck->id]);
    $match = makeLeagueMatch($olderVersion->id);

    $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $match->id])
        ->assertRedirect();

    expect($match->fresh()->league_id)->toBe($this->league->id);
});

it('rejects matches from a different deck', function () {
    $otherDeck = Deck::factory()->create(['account_id' => $this->account->id]);
    $otherVersion = DeckVersion::factory()->create(['deck_id' => $otherDeck->id]);
    $match = makeLeagueMatch($otherVersion->id);

    $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $match->id])
        ->assertSessionHasErrors('match_id');

    expect($match->fresh()->league_id)->toBeNull();
});

it('rejects when league already has 5 matches', function () {
    for ($i = 0; $i < 5; $i++) {
        makeLeagueMatch($this->version->id, ['league_id' => $this->league->id]);
    }
    $candidate = makeLeagueMatch($this->version->id);

    $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $candidate->id])
        ->assertSessionHasErrors('match_id');

    expect($candidate->fresh()->league_id)->toBeNull();
});

it('rejects non-manual leagues', function () {
    $autoLeague = League::factory()->create([
        'manual' => false,
        'deck_version_id' => $this->version->id,
    ]);
    $match = makeLeagueMatch($this->version->id);

    $this->post("/leagues/{$autoLeague->id}/matches", ['match_id' => $match->id])
        ->assertSessionHasErrors('match_id');
});

it('rejects already-linked matches', function () {
    $otherLeague = League::factory()->manual()->create(['deck_version_id' => $this->version->id]);
    $match = makeLeagueMatch($this->version->id, ['league_id' => $otherLeague->id]);

    $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $match->id])
        ->assertSessionHasErrors('match_id');
});

it('rejects incomplete matches', function () {
    $match = makeLeagueMatch($this->version->id, ['state' => MatchState::Started, 'outcome' => null, 'ended_at' => null]);

    $this->post("/leagues/{$this->league->id}/matches", ['match_id' => $match->id])
        ->assertSessionHasErrors('match_id');
});
