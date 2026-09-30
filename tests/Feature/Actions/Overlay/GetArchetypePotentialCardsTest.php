<?php

use App\Actions\Overlay\GetArchetypePotentialCards;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function potentialCard(string $name, string $type = 'Instant'): Card
{
    return Card::create(['mtgo_id' => (string) fake()->unique()->numberBetween(1000, 99999), 'oracle_id' => 'o-'.$name, 'name' => $name, 'type' => $type]);
}

/**
 * @param  array<int, array{0: Card, 1: bool, 2?: int}>  $rows  [card, sideboard, quantity]
 */
function archetypeList(Archetype $archetype, array $rows): void
{
    ArchetypeDeck::factory()->create(['archetype_id' => $archetype->id])->syncCardRows(
        collect($rows)->map(fn ($row) => ['card_id' => $row[0]->id, 'quantity' => $row[2] ?? 2, 'sideboard' => $row[1]])->all(),
    );
}

beforeEach(function () {
    $this->archetype = Archetype::factory()->create();
    $this->bolt = potentialCard('Lightning Bolt');
    $this->murktide = potentialCard('Murktide Regent', 'Creature');
    $this->heat = potentialCard('Unholy Heat');
    $this->moon = potentialCard('Blood Moon', 'Enchantment');
    $this->mountain = potentialCard('Mountain', 'Basic Land');
});

it('lists what the archetype plays, most common first, minus what has been revealed', function () {
    archetypeList($this->archetype, [[$this->bolt, false], [$this->murktide, false], [$this->heat, false]]);
    archetypeList($this->archetype, [[$this->bolt, false], [$this->murktide, false]]);
    archetypeList($this->archetype, [[$this->murktide, false]]);

    $result = GetArchetypePotentialCards::run($this->archetype, ['Lightning Bolt' => 2]);

    expect(collect($result['maindeck']->all())->pluck('name')->all())->toBe(['Murktide Regent', 'Unholy Heat']);
});

it('puts cards that are only ever sideboarded in their own group', function () {
    archetypeList($this->archetype, [[$this->bolt, false], [$this->moon, true], [$this->heat, true]]);
    archetypeList($this->archetype, [[$this->heat, false]]);

    $result = GetArchetypePotentialCards::run($this->archetype, []);

    expect(collect($result['maindeck']->all())->pluck('name')->sort()->values()->all())->toBe(['Lightning Bolt', 'Unholy Heat'])
        ->and(collect($result['sideboard']->all())->pluck('name')->all())->toBe(['Blood Moon']);
});

it('drops revealed cards from the sideboard group too', function () {
    archetypeList($this->archetype, [[$this->bolt, false], [$this->moon, true]]);

    $result = GetArchetypePotentialCards::run($this->archetype, ['Blood Moon' => 2]);

    expect($result['sideboard']->all())->toBeEmpty();
});

it('leaves basic lands out', function () {
    archetypeList($this->archetype, [[$this->bolt, false], [$this->mountain, false]]);

    $result = GetArchetypePotentialCards::run($this->archetype, []);

    expect(collect($result['maindeck']->all())->pluck('name')->all())->toBe(['Lightning Bolt']);
});

it('returns empty groups for an archetype with no downloaded lists', function () {
    $result = GetArchetypePotentialCards::run($this->archetype, []);

    expect($result['maindeck']->all())->toBeEmpty()
        ->and($result['sideboard']->all())->toBeEmpty();
});

it('shows the copies the lists usually play, less the ones already revealed', function () {
    archetypeList($this->archetype, [[$this->bolt, false, 4], [$this->murktide, false, 2]]);
    archetypeList($this->archetype, [[$this->bolt, false, 4], [$this->murktide, false, 3]]);
    archetypeList($this->archetype, [[$this->bolt, false, 3], [$this->murktide, false, 3]]);

    $result = collect(GetArchetypePotentialCards::run($this->archetype, ['Lightning Bolt' => 1])['maindeck']->all())
        ->mapWithKeys(fn ($card) => [$card->name => $card->quantity]);

    // Bolt: usually 4, one seen. Murktide: usually 3.
    expect($result->all())->toBe(['Lightning Bolt' => 3, 'Murktide Regent' => 3]);
});

it('takes the higher count when lists are split evenly', function () {
    archetypeList($this->archetype, [[$this->heat, false, 2]]);
    archetypeList($this->archetype, [[$this->heat, false, 3]]);

    expect(GetArchetypePotentialCards::run($this->archetype, [])['maindeck']->all()[0]->quantity)->toBe(3);
});

it('keeps a sideboard card whose lists play more copies than have been revealed', function () {
    archetypeList($this->archetype, [[$this->bolt, false], [$this->moon, true, 3]]);

    $result = GetArchetypePotentialCards::run($this->archetype, ['Blood Moon' => 1]);

    expect($result['sideboard']->all()[0]->quantity)->toBe(2);
});
