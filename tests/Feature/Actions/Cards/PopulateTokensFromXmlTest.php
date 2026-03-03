<?php

use App\Actions\Cards\PopulateTokensFromXml;
use App\Facades\Mtgo;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The fixture dir contains a CardDataSource/ with minimal XMLs:
 * - client_TOK.xml: tokens DOC_1001 (Goblin, Red Creature), DOC_1002 (foil clone, skipped),
 *   DOC_1003 (Zombie, Black Creature), DOC_1004 (Treasure, Colorless Artifact),
 *   DOC_1005 (Clue, Colorless Artifact), DOC_1006 (Food, W/U Enchantment Creature)
 */
function mockDataPath(): void
{
    Mtgo::shouldReceive('getLogDataPath')
        ->andReturn(realpath(__DIR__.'/../../../Fixtures'));
}

it('populates token cards from XML', function () {
    mockDataPath();

    $goblin = Card::factory()->stub()->create(['mtgo_id' => '1001']);
    $zombie = Card::factory()->stub()->create(['mtgo_id' => '1003']);

    $updated = PopulateTokensFromXml::run();

    expect($updated)->toBe(2);

    $goblin->refresh();
    expect($goblin->name)->toBe('Goblin');
    expect($goblin->type)->toBe('Token Creature');
    expect($goblin->sub_type)->toBe('Goblin');
    expect($goblin->rarity)->toBe('token');
    expect($goblin->color_identity)->toBe('R');

    $zombie->refresh();
    expect($zombie->name)->toBe('Zombie');
    expect($zombie->type)->toBe('Token Creature');
    expect($zombie->sub_type)->toBe('Zombie');
    expect($zombie->rarity)->toBe('token');
    expect($zombie->color_identity)->toBe('B');
});

it('skips CLONE_ID entries (foils)', function () {
    mockDataPath();

    $foil = Card::factory()->stub()->create(['mtgo_id' => '1002']);

    $updated = PopulateTokensFromXml::run();

    expect($updated)->toBe(0);

    $foil->refresh();
    expect($foil->name)->toBeNull();
});

it('handles artifact tokens', function () {
    mockDataPath();

    $treasure = Card::factory()->stub()->create(['mtgo_id' => '1004']);

    $updated = PopulateTokensFromXml::run();

    expect($updated)->toBe(1);

    $treasure->refresh();
    expect($treasure->name)->toBe('Treasure');
    expect($treasure->type)->toBe('Token Artifact');
    expect($treasure->color_identity)->toBe('C');
});

it('handles enchantment creature tokens with multi-color', function () {
    mockDataPath();

    $food = Card::factory()->stub()->create(['mtgo_id' => '1006']);

    $updated = PopulateTokensFromXml::run();

    expect($updated)->toBe(1);

    $food->refresh();
    expect($food->name)->toBe('Food');
    expect($food->type)->toBe('Token Enchantment Creature');
    expect($food->color_identity)->toBe('W,U');
});

it('only updates stub cards that match token IDs', function () {
    mockDataPath();

    $regular = Card::factory()->create(['mtgo_id' => '9999', 'name' => 'Lightning Bolt']);
    $stub = Card::factory()->stub()->create(['mtgo_id' => '1001']);

    $updated = PopulateTokensFromXml::run();

    expect($updated)->toBe(1);

    $regular->refresh();
    expect($regular->name)->toBe('Lightning Bolt');

    $stub->refresh();
    expect($stub->name)->toBe('Goblin');
});

it('accepts a pre-loaded card collection', function () {
    mockDataPath();

    $goblin = Card::factory()->stub()->create(['mtgo_id' => '1001']);
    Card::factory()->stub()->create(['mtgo_id' => '1003']);

    // Only pass the goblin, not the zombie
    $updated = PopulateTokensFromXml::run(collect([$goblin]));

    expect($updated)->toBe(1);

    $goblin->refresh();
    expect($goblin->name)->toBe('Goblin');
});

it('returns 0 when data path is missing', function () {
    Mtgo::shouldReceive('getLogDataPath')->andReturn('/nonexistent/path');

    Card::factory()->stub()->create(['mtgo_id' => '1001']);

    expect(PopulateTokensFromXml::run())->toBe(0);
});

it('returns 0 when no stub cards exist', function () {
    mockDataPath();

    expect(PopulateTokensFromXml::run())->toBe(0);
});

it('is idempotent — running twice produces same result', function () {
    mockDataPath();

    Card::factory()->stub()->create(['mtgo_id' => '1001']);

    $first = PopulateTokensFromXml::run();
    expect($first)->toBe(1);

    // Second run — card already has name, won't be in whereNull('name') query
    $second = PopulateTokensFromXml::run();
    expect($second)->toBe(0);
});
