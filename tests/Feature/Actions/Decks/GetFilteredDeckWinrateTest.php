<?php

use App\Actions\Decks\GetFilteredDeckWinrate;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('aggregates game winrate across arbitrary version ids', function () {
    $deckA = Deck::factory()->create();
    $deckB = Deck::factory()->create();
    $versionA = DeckVersion::factory()->create(['deck_id' => $deckA->id]);
    $versionB = DeckVersion::factory()->create(['deck_id' => $deckB->id]);

    $matchA = MtgoMatch::factory()->create(['deck_version_id' => $versionA->id]);
    $matchB = MtgoMatch::factory()->create(['deck_version_id' => $versionB->id]);
    Game::factory()->create(['match_id' => $matchA->id, 'won' => true]);
    Game::factory()->create(['match_id' => $matchA->id, 'won' => true]);
    Game::factory()->create(['match_id' => $matchB->id, 'won' => false]);
    Game::factory()->create(['match_id' => $matchB->id, 'won' => true]);

    $winrate = GetFilteredDeckWinrate::forVersionIds([$versionA->id, $versionB->id]);

    expect($winrate->games)->toBe(4)
        ->and($winrate->wins)->toBe(3)
        ->and($winrate->rate)->toBe(0.75);
});

it('returns the neutral prior for no versions', function () {
    $winrate = GetFilteredDeckWinrate::forVersionIds([]);

    expect($winrate->games)->toBe(0)->and($winrate->rate)->toBe(0.5);
});
