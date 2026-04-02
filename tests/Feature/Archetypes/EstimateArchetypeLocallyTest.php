<?php

use App\Actions\Archetypes\EstimateArchetypeLocally;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createArchetypeWithCards(array $attributes, array $cards): Archetype
{
    $archetype = Archetype::factory()->withDecklist()->create($attributes);

    $pivotData = [];
    foreach ($cards as $cardData) {
        $card = Card::firstOrCreate(
            ['oracle_id' => $cardData['oracle_id']],
            [
                'mtgo_id' => $cardData['mtgo_id'],
                'name' => $cardData['name'],
                'type' => $cardData['type'] ?? 'Instant',
            ]
        );
        $pivotData[$card->id] = [
            'quantity' => $cardData['quantity'],
            'sideboard' => $cardData['sideboard'] ?? false,
        ];
    }

    $archetype->cards()->sync($pivotData);

    return $archetype;
}

it('matches a deck against a local archetype', function () {
    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
            ['oracle_id' => 'spike', 'mtgo_id' => 101, 'name' => 'Lava Spike', 'quantity' => 4],
            ['oracle_id' => 'guide', 'mtgo_id' => 102, 'name' => 'Goblin Guide', 'quantity' => 4],
            ['oracle_id' => 'swift', 'mtgo_id' => 103, 'name' => 'Monastery Swiftspear', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
        ['mtgo_id' => 102, 'quantity' => 4],
        ['mtgo_id' => 103, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['confidence'])->toBeGreaterThan(0.5);
});

it('returns null when no archetypes exist for the format', function () {
    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'legacy');

    expect($result)->toBeNull();
});

it('returns null when no cards overlap', function () {
    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 999, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->toBeNull();
});

it('picks the best matching archetype', function () {
    $burn = createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
            ['oracle_id' => 'spike', 'mtgo_id' => 101, 'name' => 'Lava Spike', 'quantity' => 4],
            ['oracle_id' => 'guide', 'mtgo_id' => 102, 'name' => 'Goblin Guide', 'quantity' => 4],
        ]
    );

    createArchetypeWithCards(
        ['name' => 'Control', 'format' => 'modern'],
        [
            ['oracle_id' => 'counter', 'mtgo_id' => 200, 'name' => 'Counterspell', 'quantity' => 4],
            ['oracle_id' => 'verdict', 'mtgo_id' => 201, 'name' => 'Supreme Verdict', 'quantity' => 2],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
        ['mtgo_id' => 102, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($burn->id);
});

it('skips archetypes without downloaded decklists', function () {
    Archetype::factory()->create([
        'name' => 'No Decklist',
        'format' => 'modern',
        'decklist_downloaded_at' => null,
    ]);

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->toBeNull();
});

it('normalizes MTGO format codes like CMODERN to modern', function () {
    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
            ['oracle_id' => 'spike', 'mtgo_id' => 101, 'name' => 'Lava Spike', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'CMODERN');

    expect($result)->not->toBeNull();
    expect($result['confidence'])->toBeGreaterThan(0.5);
});

it('applies ambiguity penalty when top two scores are close', function () {
    // Two archetypes that are nearly identical — differ by 1 card each
    createArchetypeWithCards(
        ['name' => 'Deck A', 'format' => 'modern'],
        [
            ['oracle_id' => 'shared1', 'mtgo_id' => 100, 'name' => 'Shared Card 1', 'quantity' => 4],
            ['oracle_id' => 'unique_a', 'mtgo_id' => 102, 'name' => 'Unique A', 'quantity' => 4],
        ]
    );

    createArchetypeWithCards(
        ['name' => 'Deck B', 'format' => 'modern'],
        [
            ['oracle_id' => 'shared1', 'mtgo_id' => 100, 'name' => 'Shared Card 1', 'quantity' => 4],
            ['oracle_id' => 'unique_b', 'mtgo_id' => 103, 'name' => 'Unique B', 'quantity' => 4],
        ]
    );

    // Input only has the shared card — both archetypes score equally
    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();

    // Without penalty the score would be ~1.0 + 0.35 + 0.05 = 1.4
    // With penalty (×0.7) it should be ~0.98
    // Key check: confidence is reduced from what it would be without ambiguity
    expect($result['confidence'])->toBeLessThan(1.0);
});
