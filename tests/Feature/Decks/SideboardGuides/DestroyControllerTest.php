<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes the guide, its cards and the matchup notes, leaving other pairs alone', function () {
    $deck = Deck::factory()->create();
    $blink = Archetype::factory()->create();
    $tron = Archetype::factory()->create();

    $guide = SideboardGuide::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);
    SideboardGuideCard::factory()->create(['sideboard_guide_id' => $guide->id]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);

    $keep = DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $tron->id]);

    $this->delete(route('decks.sideboard-guides.destroy', [$deck, $guide]))
        ->assertRedirect(route('decks.sideboard-guides.index', $deck));

    expect(SideboardGuide::count())->toBe(0);
    expect(SideboardGuideCard::count())->toBe(0);
    expect(DeckArchetypeNote::pluck('id')->all())->toBe([$keep->id]);
});

it('returns 404 for a guide that belongs to another deck', function () {
    $guide = SideboardGuide::factory()->create();
    $other = Deck::factory()->create();

    $this->delete(route('decks.sideboard-guides.destroy', [$other, $guide]))->assertNotFound();

    expect(SideboardGuide::count())->toBe(1);
});
