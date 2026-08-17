<?php

use App\Actions\Archetypes\EstimateArchetypeLocally;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createArchetypeWithCards(array $attributes, array $cards): array
{
    $archetype = Archetype::factory()->withDecklist()->create($attributes);
    $deck = ArchetypeDeck::factory()->for($archetype)->create();

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

    $deck->cards()->sync($pivotData);

    return ['archetype' => $archetype, 'deck' => $deck];
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
    ['archetype' => $burn, 'deck' => $burnDeck] = createArchetypeWithCards(
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
    expect($result['archetype_deck_id'])->toBe($burnDeck->id);
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

it('never reports confidence above 1.0', function () {
    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
            ['oracle_id' => 'spike', 'mtgo_id' => 101, 'name' => 'Lava Spike', 'quantity' => 4],
        ]
    );

    // A perfect full-coverage match — the strongest possible signal.
    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['confidence'])->toBeLessThanOrEqual(1.0);
});

it('normalizes MTGO format codes like CPIONEER to pioneer', function () {
    createArchetypeWithCards(
        ['name' => 'Phoenix', 'format' => 'pioneer'],
        [
            ['oracle_id' => 'phoenix', 'mtgo_id' => 400, 'name' => 'Arclight Phoenix', 'quantity' => 4],
            ['oracle_id' => 'pieces', 'mtgo_id' => 401, 'name' => 'Pieces of the Puzzle', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 400, 'quantity' => 4],
        ['mtgo_id' => 401, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'CPIONEER');

    expect($result)->not->toBeNull();
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

it('picks the best matching deck variant under an archetype', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);

    $cardA = Card::factory()->create(['oracle_id' => 'a', 'mtgo_id' => '1']);
    $cardB = Card::factory()->create(['oracle_id' => 'b', 'mtgo_id' => '2']);
    $cardC = Card::factory()->create(['oracle_id' => 'c', 'mtgo_id' => '3']);

    // Variant 1: A + B
    $variant1 = ArchetypeDeck::factory()->for($archetype)->create(['uuid' => 'v1', 'seen_count' => 5]);
    $variant1->cards()->attach([
        $cardA->id => ['quantity' => 4, 'sideboard' => false],
        $cardB->id => ['quantity' => 4, 'sideboard' => false],
    ]);

    // Variant 2: A + C  (input matches this one)
    $variant2 = ArchetypeDeck::factory()->for($archetype)->create(['uuid' => 'v2', 'seen_count' => 3]);
    $variant2->cards()->attach([
        $cardA->id => ['quantity' => 4, 'sideboard' => false],
        $cardC->id => ['quantity' => 4, 'sideboard' => false],
    ]);

    $input = collect([
        ['mtgo_id' => '1', 'quantity' => 4],
        ['mtgo_id' => '3', 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($input, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($archetype->id);
    expect($result['archetype_deck_id'])->toBe($variant2->id);
});

it('returns null when no deck has any card overlap', function () {
    $archetype = Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    $cardA = Card::factory()->create(['oracle_id' => 'a', 'mtgo_id' => '1']);
    $unrelated = Card::factory()->create(['oracle_id' => 'unrelated', 'mtgo_id' => '999']);

    $variant = ArchetypeDeck::factory()->for($archetype)->create();
    $variant->cards()->attach($cardA->id, ['quantity' => 4, 'sideboard' => false]);

    $input = collect([['mtgo_id' => '999', 'quantity' => 4]]);

    expect(EstimateArchetypeLocally::run($input, 'modern'))->toBeNull();
});

it('ignores basic lands when matching — they are too broad to discriminate archetypes', function () {
    createArchetypeWithCards(
        ['name' => 'Dimir Murktide', 'format' => 'modern'],
        [
            ['oracle_id' => 'island', 'mtgo_id' => 93534, 'name' => 'Island', 'type' => 'Basic Land — Island', 'quantity' => 4],
            ['oracle_id' => 'murktide', 'mtgo_id' => 500, 'name' => 'Murktide Regent', 'quantity' => 4],
            ['oracle_id' => 'dragon', 'mtgo_id' => 501, 'name' => 'Dragons Rage Channeler', 'quantity' => 4],
        ]
    );

    // Input only shares the basic land — must not match.
    $inputCards = collect([
        ['mtgo_id' => 93534, 'quantity' => 3],
    ]);

    expect(EstimateArchetypeLocally::run($inputCards, 'modern'))->toBeNull();
});

it('uses nonbasic lands to discriminate land-defined archetypes like Tron', function () {
    ['archetype' => $tron] = createArchetypeWithCards(
        ['name' => 'Tron', 'format' => 'modern'],
        [
            ['oracle_id' => 'tower', 'mtgo_id' => 300, 'name' => "Urza's Tower", 'type' => "Land — Urza's Tower", 'quantity' => 4],
            ['oracle_id' => 'mine', 'mtgo_id' => 301, 'name' => "Urza's Mine", 'type' => "Land — Urza's Mine", 'quantity' => 4],
            ['oracle_id' => 'plant', 'mtgo_id' => 302, 'name' => "Urza's Power Plant", 'type' => "Land — Urza's Power-Plant", 'quantity' => 4],
            ['oracle_id' => 'karn', 'mtgo_id' => 303, 'name' => 'Karn Liberated', 'type' => 'Legendary Planeswalker — Karn', 'quantity' => 3],
        ]
    );

    createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
        ]
    );

    // Opponent revealed only the Tron lands — that IS the archetype's identity.
    $inputCards = collect([
        ['mtgo_id' => 300, 'quantity' => 2],
        ['mtgo_id' => 301, 'quantity' => 2],
        ['mtgo_id' => 302, 'quantity' => 1],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($tron->id);
});

it('does not count lands toward quantity overlap', function () {
    ['archetype' => $burn] = createArchetypeWithCards(
        ['name' => 'Burn', 'format' => 'modern'],
        [
            ['oracle_id' => 'bolt', 'mtgo_id' => 100, 'name' => 'Lightning Bolt', 'quantity' => 4],
            ['oracle_id' => 'spike', 'mtgo_id' => 101, 'name' => 'Lava Spike', 'quantity' => 4],
            ['oracle_id' => 'mountain', 'mtgo_id' => 102, 'name' => 'Mountain', 'type' => 'Basic Land', 'quantity' => 12],
        ]
    );

    // A pile of lands plus two real spells — score must come from the spells,
    // not the lands inflating quantity overlap.
    $inputCards = collect([
        ['mtgo_id' => 100, 'quantity' => 4],
        ['mtgo_id' => 101, 'quantity' => 4],
        ['mtgo_id' => 102, 'quantity' => 20],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'modern');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->toBe($burn->id);
    // 2 of 2 non-land cards matched → high confidence, lands excluded entirely.
    expect($result['confidence'])->toBeGreaterThan(0.9);
});

it('skips archetypes with no decks', function () {
    Archetype::factory()->create(['format' => 'modern', 'decklist_downloaded_at' => now()]);
    Card::factory()->create(['oracle_id' => 'a', 'mtgo_id' => '1']);

    $input = collect([['mtgo_id' => '1', 'quantity' => 4]]);

    expect(EstimateArchetypeLocally::run($input, 'modern'))->toBeNull();
});

/**
 * Build a 15-distinct-card archetype deck for sample-size tests.
 *
 * @return array<int, array{oracle_id: string, mtgo_id: int, name: string, quantity: int}>
 */
function largeDeckCards(): array
{
    $cards = [];
    for ($i = 1; $i <= 15; $i++) {
        $cards[] = ['oracle_id' => "c{$i}", 'mtgo_id' => 1000 + $i, 'name' => "Card {$i}", 'quantity' => 4];
    }

    return $cards;
}

it('does not short-circuit on thin observations of a large deck', function () {
    createArchetypeWithCards(['name' => 'Big Deck', 'format' => 'pauper'], largeDeckCards());

    // Only two of the deck's fifteen distinct non-land cards were observed.
    $inputCards = collect([
        ['mtgo_id' => 1001, 'quantity' => 4],
        ['mtgo_id' => 1002, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'pauper');

    expect($result)->not->toBeNull();
    expect($result['archetype_id'])->not->toBeNull();
    // Two cards is too little evidence to confidently classify. Confidence must
    // fall below the 0.8 local short-circuit threshold so the API is consulted.
    expect($result['confidence'])->toBeLessThan(0.8);
});

it('still short-circuits when many distinct cards of a large deck are observed', function () {
    createArchetypeWithCards(['name' => 'Big Deck', 'format' => 'pauper'], largeDeckCards());

    // Observed nine of the fifteen distinct cards — strong evidence.
    $inputCards = collect(range(1, 9))
        ->map(fn ($i) => ['mtgo_id' => 1000 + $i, 'quantity' => 4]);

    $result = EstimateArchetypeLocally::run($inputCards, 'pauper');

    expect($result)->not->toBeNull();
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.8);
});

it('stays confident when a small deck is fully observed', function () {
    createArchetypeWithCards(
        ['name' => 'Tiny Deck', 'format' => 'pauper'],
        [
            ['oracle_id' => 'a', 'mtgo_id' => 2001, 'name' => 'A', 'quantity' => 4],
            ['oracle_id' => 'b', 'mtgo_id' => 2002, 'name' => 'B', 'quantity' => 4],
        ]
    );

    $inputCards = collect([
        ['mtgo_id' => 2001, 'quantity' => 4],
        ['mtgo_id' => 2002, 'quantity' => 4],
    ]);

    $result = EstimateArchetypeLocally::run($inputCards, 'pauper');

    expect($result)->not->toBeNull();
    // Full coverage of a genuinely small deck stays trustworthy despite few cards.
    expect($result['confidence'])->toBeGreaterThanOrEqual(0.8);
});
