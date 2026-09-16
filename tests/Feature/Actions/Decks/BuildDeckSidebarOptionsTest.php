<?php

use App\Actions\Decks\BuildDeckSidebarOptions;
use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sidebarDeck(array $attributes = [], int $won = 0, int $lost = 0, int $drawn = 0): Deck
{
    $deck = Deck::factory()->create($attributes);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);

    if ($won > 0) {
        MtgoMatch::factory()->won()->count($won)->create(['deck_version_id' => $version->id]);
    }
    if ($lost > 0) {
        MtgoMatch::factory()->lost()->count($lost)->create(['deck_version_id' => $version->id]);
    }
    if ($drawn > 0) {
        MtgoMatch::factory()->count($drawn)->create([
            'deck_version_id' => $version->id,
            'outcome' => MatchOutcome::Draw,
        ]);
    }

    return $deck;
}

it('counts decks per format with display labels, excluding limited decks', function () {
    Deck::factory()->count(2)->create(['format' => 'CModern']);
    Deck::factory()->create(['format' => 'CPauper']);
    Deck::factory()->create(['format' => EnsureLimitedDeckVersion::FORMAT]);

    $options = BuildDeckSidebarOptions::formatOptions(hideDeleted: true)->toCollection();

    expect($options)->toHaveCount(2);
    expect($options->firstWhere('value', 'CModern')->count)->toBe(2);
    expect($options->firstWhere('value', 'CModern')->label)->toBe('Modern');
    expect($options->firstWhere('value', 'CPauper')->count)->toBe(1);
});

it('excludes trashed decks from format counts when hiding deleted', function () {
    Deck::factory()->create(['format' => 'CModern']);
    Deck::factory()->create(['format' => 'CModern'])->delete();

    expect(BuildDeckSidebarOptions::formatOptions(hideDeleted: true)->toCollection()->first()->count)->toBe(1);
    expect(BuildDeckSidebarOptions::formatOptions(hideDeleted: false)->toCollection()->first()->count)->toBe(2);
});

it('aggregates deck count and match record per archetype, draws included in total', function () {
    $tron = Archetype::factory()->create(['name' => 'Eldrazi Tron', 'color_identity' => 'G', 'format' => 'modern']);
    sidebarDeck(['archetype_id' => $tron->id], won: 6, lost: 4);
    sidebarDeck(['archetype_id' => $tron->id], won: 1, lost: 1, drawn: 2);

    $options = BuildDeckSidebarOptions::archetypeOptions(format: null, hideDeleted: true)->toCollection();

    expect($options)->toHaveCount(1);
    $row = $options->first();
    expect($row->id)->toBe($tron->id);
    expect($row->name)->toBe('Eldrazi Tron');
    expect($row->colorIdentity)->toBe('G');
    expect($row->format)->toBe('modern');
    expect($row->deckCount)->toBe(2);
    expect($row->record->wins)->toBe(7);
    expect($row->record->losses)->toBe(5);
    expect($row->record->draws)->toBe(2);
    expect($row->record->total)->toBe(14);
    // 7 / 14, not 7 / 12.
    expect($row->record->winrate)->toBe(50);
});

it('keeps archetypes whose decks have no matches and only counts complete matches', function () {
    $burn = Archetype::factory()->create(['name' => 'Burn']);
    $deck = Deck::factory()->create(['archetype_id' => $burn->id]);
    $version = DeckVersion::factory()->create(['deck_id' => $deck->id]);
    MtgoMatch::factory()->won()->create(['deck_version_id' => $version->id, 'state' => MatchState::InProgress]);

    $row = BuildDeckSidebarOptions::archetypeOptions(format: null, hideDeleted: true)->toCollection()->first();

    expect($row->deckCount)->toBe(1);
    expect($row->record->total)->toBe(0);
    expect($row->record->winrate)->toBe(0);
});

it('sorts archetypes by deck count descending then name', function () {
    $a = Archetype::factory()->create(['name' => 'Affinity']);
    $z = Archetype::factory()->create(['name' => 'Zoo']);
    $big = Archetype::factory()->create(['name' => 'Tron']);

    Deck::factory()->create(['archetype_id' => $z->id]);
    Deck::factory()->create(['archetype_id' => $a->id]);
    Deck::factory()->count(2)->create(['archetype_id' => $big->id]);

    $names = BuildDeckSidebarOptions::archetypeOptions(format: null, hideDeleted: true)
        ->toCollection()->pluck('name')->all();

    expect($names)->toBe(['Tron', 'Affinity', 'Zoo']);
});

it('respects the format filter and excludes limited decks from archetype options', function () {
    $modern = Archetype::factory()->create(['name' => 'Modern Tron']);
    $legacy = Archetype::factory()->create(['name' => 'Legacy Tron']);
    Deck::factory()->create(['archetype_id' => $modern->id, 'format' => 'CModern']);
    Deck::factory()->create(['archetype_id' => $legacy->id, 'format' => 'CLegacy']);
    Deck::factory()->create(['archetype_id' => $modern->id, 'format' => EnsureLimitedDeckVersion::FORMAT]);

    $options = BuildDeckSidebarOptions::archetypeOptions(format: 'CModern', hideDeleted: true)->toCollection();

    expect($options)->toHaveCount(1);
    expect($options->first()->name)->toBe('Modern Tron');
    expect($options->first()->deckCount)->toBe(1);
});

it('counts unclassified decks within the format and archived scope', function () {
    Deck::factory()->count(2)->create(['archetype_id' => null, 'format' => 'CModern']);
    Deck::factory()->create(['archetype_id' => null, 'format' => 'CPauper']);
    Deck::factory()->create(['archetype_id' => null, 'format' => 'CModern'])->delete();
    Deck::factory()->create(['archetype_id' => Archetype::factory()->create()->id, 'format' => 'CModern']);

    expect(BuildDeckSidebarOptions::unclassifiedCount(format: null, hideDeleted: true))->toBe(3);
    expect(BuildDeckSidebarOptions::unclassifiedCount(format: 'CModern', hideDeleted: true))->toBe(2);
    expect(BuildDeckSidebarOptions::unclassifiedCount(format: 'CModern', hideDeleted: false))->toBe(3);
});
