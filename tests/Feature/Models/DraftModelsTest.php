<?php

// tests/Feature/Models/DraftModelsTest.php

use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('defaults a league to constructed kind with no set code', function () {
    $league = League::factory()->create();

    expect($league->kind)->toBe(LeagueKind::Constructed)
        ->and($league->set_code)->toBeNull()
        ->and($league->mtgo_course_id)->toBeNull();
});

it('links a draft to a league and its picks', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft, 'set_code' => 'HOB', 'mtgo_course_id' => 35746768]);
    $draft = Draft::factory()->for($league)->create(['state' => DraftState::Picking]);
    DraftPick::factory()->for($draft)->create(['ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'cards_available' => [1, 2, 3]]);

    expect($league->fresh()->draft->id)->toBe($draft->id)
        ->and($draft->picks()->count())->toBe(1)
        ->and($draft->picks()->first()->cards_available)->toBe([1, 2, 3])
        ->and($draft->picks()->first()->reservations)->toBe([]);
});

it('rejects a second pick with the same ordinal in a draft', function () {
    $draft = Draft::factory()->create();
    DraftPick::factory()->for($draft)->create(['ordinal' => 7]);

    expect(fn () => DraftPick::factory()->for($draft)->create(['ordinal' => 7]))
        ->toThrow(QueryException::class);
});

it('rejects two leagues sharing event_id and course id', function () {
    League::factory()->create(['event_id' => 11039, 'mtgo_course_id' => 1]);

    expect(fn () => League::factory()->create(['event_id' => 11039, 'mtgo_course_id' => 1]))
        ->toThrow(QueryException::class);
});

it('stores a registered deck snapshot per league and match', function () {
    $league = League::factory()->create(['kind' => LeagueKind::Draft]);
    $snapshot = LimitedDeckSnapshot::create([
        'league_id' => $league->id,
        'match_id' => null,
        'source' => 'registered',
        'cards' => [['catalog_id' => 153896, 'quantity' => 1, 'sideboard' => true]],
        'signature' => 'sig',
        'captured_at' => now(),
    ]);

    expect($snapshot->cards[0]['catalog_id'])->toBe(153896);
});
