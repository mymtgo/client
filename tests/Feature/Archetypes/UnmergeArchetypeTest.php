<?php

use App\Actions\Archetypes\UnmergeArchetype;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('nulls merged_into_id', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);

    UnmergeArchetype::run($source);

    expect($source->fresh()->merged_into_id)->toBeNull();
});

it('refuses to unmerge a standalone archetype', function (): void {
    $archetype = Archetype::factory()->create(['merged_into_id' => null]);

    expect(fn () => UnmergeArchetype::run($archetype))
        ->toThrow(InvalidArgumentException::class);
});

it('leaves source variants and history untouched', function (): void {
    $parent = Archetype::factory()->create();
    $source = Archetype::factory()->create(['merged_into_id' => $parent->id]);
    $variant = ArchetypeDeck::factory()->for($source)->create();

    $match = MtgoMatch::factory()->create();
    $player = Player::factory()->create();

    $rowId = DB::table('match_archetypes')->insertGetId([
        'mtgo_match_id' => $match->id,
        'archetype_id' => $source->id,
        'archetype_deck_id' => $variant->id,
        'player_id' => $player->id,
        'confidence' => 1.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    UnmergeArchetype::run($source);

    expect($variant->fresh()->archetype_id)->toBe($source->id);

    $row = DB::table('match_archetypes')->find($rowId);
    expect((int) $row->archetype_id)->toBe($source->id);
    expect((int) $row->archetype_deck_id)->toBe($variant->id);
});
