<?php

use App\Models\Archetype;
use App\Models\Card;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\DeckVersion;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Card::create(['mtgo_id' => '201', 'oracle_id' => 'o-rip', 'name' => 'Rest in Peace', 'type' => 'Enchantment']);
    Card::create(['mtgo_id' => '202', 'oracle_id' => 'o-bolt', 'name' => 'Lightning Bolt', 'type' => 'Instant']);
});

function editableGuide(): array
{
    $deck = Deck::factory()->create();
    $version = DeckVersion::create([
        'deck_id' => $deck->id,
        'signature' => base64_encode('202:4:0|201:2:1'),
        'modified_at' => now(),
    ]);
    $archetype = Archetype::factory()->create(['name' => 'Esper Blink']);
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    return [$deck, $version, $archetype, $guide];
}

it('renders the editor with every card, planned quantities, notes and the header summary', function () {
    [$deck, , $archetype, $guide] = editableGuide();

    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id, 'oracle_id' => 'o-rip', 'quantity' => 2]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id, 'body' => 'Race them']);

    $this->get(route('decks.sideboard-guides.edit', [$deck, $guide]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('decks/SideboardGuideEdit')
            ->where('currentPage', 'sideboard-guides')
            ->where('guide.id', $guide->id)
            ->where('guide.archetypeName', 'Esper Blink')
            ->where('hasVersion', true)
            ->has('sideboard.sidedIn', 1)
            ->where('sideboard.sidedIn.0.oracleId', 'o-rip')
            ->where('sideboard.sidedIn.0.quantity', 2)
            ->where('sideboard.sidedIn.0.plannedQuantity', 2)
            ->has('sideboard.sidedOut', 1)
            ->where('sideboard.sidedOut.0.oracleId', 'o-bolt')
            ->where('sideboard.sidedOut.0.quantity', 4)
            ->where('sideboard.sidedOut.0.plannedQuantity', null)
            ->has('notes.current', 1)
            ->where('notes.current.0.body', 'Race them')
        );
});

it('renders an empty editor when the deck has no version yet', function () {
    $deck = Deck::factory()->create();
    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id]);

    $this->get(route('decks.sideboard-guides.edit', [$deck, $guide]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hasVersion', false)
            ->where('sideboard', null)
        );
});

it('returns 404 for a guide that belongs to another deck', function () {
    [, , , $guide] = editableGuide();
    $other = Deck::factory()->create();

    $this->get(route('decks.sideboard-guides.edit', [$other, $guide]))->assertNotFound();
});
