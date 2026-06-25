<?php

use App\Models\Archetype;
use App\Models\Game;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createMatchWithOpponent(): MtgoMatch
{
    $match = MtgoMatch::factory()->create();
    Game::factory()->create(['match_id' => $match->id, 'opp_instance' => 1]);

    return $match;
}

it('sets archetype for multiple matches', function () {
    $archetype = Archetype::factory()->create();
    $matches = collect([
        createMatchWithOpponent(),
        createMatchWithOpponent(),
        createMatchWithOpponent(),
    ]);

    $this->patch('/matches/bulk-archetype', [
        'match_ids' => $matches->pluck('id')->all(),
        'archetype_id' => $archetype->id,
    ])->assertRedirect();

    foreach ($matches as $match) {
        $this->assertDatabaseHas('match_archetypes', [
            'mtgo_match_id' => $match->id,
            'is_opponent' => true,
            'archetype_id' => $archetype->id,
            'confidence' => 1.0,
        ]);
    }
});

it('updates existing archetypes when bulk setting', function () {
    $oldArchetype = Archetype::factory()->create();
    $newArchetype = Archetype::factory()->create();
    $match = createMatchWithOpponent();

    MatchArchetype::create([
        'mtgo_match_id' => $match->id,
        'is_opponent' => true,
        'archetype_id' => $oldArchetype->id,
        'confidence' => 0.5,
    ]);

    $this->patch('/matches/bulk-archetype', [
        'match_ids' => [$match->id],
        'archetype_id' => $newArchetype->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('match_archetypes', [
        'mtgo_match_id' => $match->id,
        'archetype_id' => $newArchetype->id,
        'confidence' => 1.0,
    ]);

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->count())->toBe(1);
});

it('validates match_ids and archetype_id are required', function () {
    $this->patch('/matches/bulk-archetype', [])
        ->assertSessionHasErrors(['match_ids', 'archetype_id']);
});

it('validates archetype exists', function () {
    $match = MtgoMatch::factory()->create();

    $this->patch('/matches/bulk-archetype', [
        'match_ids' => [$match->id],
        'archetype_id' => 99999,
    ])->assertSessionHasErrors(['archetype_id']);
});

it('skips matches with no opponent', function () {
    $archetype = Archetype::factory()->create();
    $match = MtgoMatch::factory()->create();

    $this->patch('/matches/bulk-archetype', [
        'match_ids' => [$match->id],
        'archetype_id' => $archetype->id,
    ])->assertRedirect();

    expect(MatchArchetype::where('mtgo_match_id', $match->id)->exists())->toBeFalse();
});
