<?php

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckArchetypeNote;
use App\Models\SideboardGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runSideboardGuidesMigration(): void
{
    $migration = require database_path('migrations/2026_09_03_100000_create_sideboard_guides_tables.php');
    $migration->up();
}

it('creates one guide per noted deck and archetype pair', function () {
    $deck = Deck::factory()->create();
    $other = Deck::factory()->create();
    $blink = Archetype::factory()->create();
    $tron = Archetype::factory()->create();

    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $blink->id]);
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $tron->id]);
    DeckArchetypeNote::factory()->create(['deck_id' => $other->id, 'archetype_id' => $blink->id]);

    Schema::dropIfExists('sideboard_guide_cards');
    Schema::dropIfExists('sideboard_guides');

    runSideboardGuidesMigration();

    expect(SideboardGuide::count())->toBe(3);
    expect(SideboardGuide::where('deck_id', $deck->id)->where('archetype_id', $blink->id)->exists())->toBeTrue();
    expect(SideboardGuide::where('deck_id', $other->id)->where('archetype_id', $blink->id)->exists())->toBeTrue();
});

it('is safe to run twice', function () {
    $deck = Deck::factory()->create();
    $archetype = Archetype::factory()->create();
    DeckArchetypeNote::factory()->create(['deck_id' => $deck->id, 'archetype_id' => $archetype->id]);

    Schema::dropIfExists('sideboard_guide_cards');
    Schema::dropIfExists('sideboard_guides');

    runSideboardGuidesMigration();
    runSideboardGuidesMigration();

    expect(SideboardGuide::count())->toBe(1);
});
