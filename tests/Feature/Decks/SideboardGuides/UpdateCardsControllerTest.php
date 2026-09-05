<?php

use App\Enums\SideboardDirection;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Card::create(['mtgo_id' => '201', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);
    Card::create(['mtgo_id' => '202', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
    Card::create(['mtgo_id' => '203', 'oracle_id' => 'o-cut', 'name' => 'Cut Down', 'type' => 'Instant']);
});

function savableGuide(): array
{
    $deck = Deck::factory()->create();
    DeckVersion::create([
        'deck_id' => $deck->id,
        // 4 Bolt main, 2 RiP board, 1 Cut Down board.
        'signature' => base64_encode('202:4:0|201:2:1|203:1:1'),
        'modified_at' => now(),
    ]);
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => Archetype::factory()->create()->id]);

    return [$deck, $guide];
}

it('replaces the card set atomically', function () {
    [$deck, $guide] = savableGuide();
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-cut', 'quantity' => 1]);

    $before = $guide->updated_at;
    $this->travel(1)->minutes();

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [
            ['oracle_id' => 'o-rip', 'direction' => 'in', 'quantity' => 2],
            ['oracle_id' => 'o-bolt', 'direction' => 'out', 'quantity' => 2],
        ],
    ])->assertRedirect();

    $cards = $guide->fresh()->cards->sortBy('oracle_id')->values();

    expect($cards)->toHaveCount(2);
    expect($cards[0]->oracle_id)->toBe('o-bolt');
    expect($cards[0]->direction)->toBe(SideboardDirection::Out);
    expect($cards[0]->quantity)->toBe(2);
    expect($cards[1]->oracle_id)->toBe('o-rip');
    expect($cards[1]->direction)->toBe(SideboardDirection::In);
    expect($guide->fresh()->updated_at->gt($before))->toBeTrue();
});

it('clears the plan when cards is empty', function () {
    [$deck, $guide] = savableGuide();
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-cut', 'quantity' => 1]);

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), ['cards' => []])->assertRedirect();

    expect($guide->fresh()->cards)->toHaveCount(0);
});

it('rejects bringing in more copies than the sideboard holds', function () {
    [$deck, $guide] = savableGuide();

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [['oracle_id' => 'o-rip', 'direction' => 'in', 'quantity' => 3]],
    ])->assertSessionHasErrors('cards.0.quantity');

    expect($guide->fresh()->cards)->toHaveCount(0);
});

it('rejects taking out more copies than the maindeck holds', function () {
    [$deck, $guide] = savableGuide();

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [['oracle_id' => 'o-bolt', 'direction' => 'out', 'quantity' => 5]],
    ])->assertSessionHasErrors('cards.0.quantity');
});

it('rejects a card that is not in that zone of the current version unless it is already on the guide', function () {
    [$deck, $guide] = savableGuide();

    // Bolt is maindeck only, so bringing it in is not possible.
    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [['oracle_id' => 'o-bolt', 'direction' => 'in', 'quantity' => 1]],
    ])->assertSessionHasErrors('cards.0.oracle_id');

    // A stale entry already saved on the guide may be resubmitted unchanged.
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-gone', 'quantity' => 2]);

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [['oracle_id' => 'o-gone', 'direction' => 'in', 'quantity' => 2]],
    ])->assertSessionDoesntHaveErrors()->assertRedirect();
});

it('rejects the same card listed twice for one direction', function () {
    [$deck, $guide] = savableGuide();

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [
            ['oracle_id' => 'o-rip', 'direction' => 'in', 'quantity' => 1],
            ['oracle_id' => 'o-rip', 'direction' => 'in', 'quantity' => 1],
        ],
    ])->assertSessionHasErrors('cards.1.oracle_id');

    expect($guide->fresh()->cards)->toHaveCount(0);
});

it('validates the card shape', function () {
    [$deck, $guide] = savableGuide();

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [
        'cards' => [['oracle_id' => 'o-rip', 'direction' => 'sideways', 'quantity' => 0]],
    ])->assertSessionHasErrors(['cards.0.direction', 'cards.0.quantity']);

    $this->put(route('decks.sideboard-guides.update', [$deck, $guide]), [])
        ->assertSessionHasErrors('cards');
});

it('returns 404 for a guide that belongs to another deck', function () {
    [, $guide] = savableGuide();
    $other = Deck::factory()->create();

    $this->put(route('decks.sideboard-guides.update', [$other, $guide]), ['cards' => []])->assertNotFound();
});
