<?php

use App\Actions\Limited\Read\BuildDraftReview;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds header, picks with wheel data and resolved cards', function () {
    $draft = Draft::factory()->finished()->create(['seat_index' => 5, 'seat_count' => 8, 'booster_catalog_id' => 154732]);
    Card::factory()->create(['mtgo_id' => '1', 'name' => 'Harper', 'colors' => 'U', 'rarity' => 'common']);
    Card::factory()->create(['mtgo_id' => '8', 'name' => 'Bard', 'colors' => 'W', 'rarity' => 'uncommon']);
    $t = Carbon::parse('2026-08-22 11:00:00');
    DraftPick::factory()->create([
        'draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1, 'pack_id' => 500, 'direction' => 0,
        'cards_available' => [1, 2, 3, 8], 'picked_catalog_id' => 8,
        'reservations' => [['catalog_id' => 3, 'at' => $t->copy()->addSeconds(5)->toIso8601String()], ['catalog_id' => 8, 'at' => $t->copy()->addSeconds(17)->toIso8601String()]],
        'shown_at' => $t, 'picked_at' => $t->copy()->addSeconds(26), 'deadline_at' => $t->copy()->addSeconds(69), 'note' => 'Bard is a bomb',
    ]);
    foreach (range(2, 8) as $o) {
        DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => $o, 'pack_number' => 1, 'pick_number' => $o, 'pack_id' => 500 + $o, 'cards_available' => [100 + $o], 'picked_catalog_id' => 100 + $o]);
    }
    DraftPick::factory()->create(['draft_id' => $draft->id, 'ordinal' => 9, 'pack_number' => 1, 'pick_number' => 9, 'pack_id' => 500, 'cards_available' => [1, 3], 'picked_catalog_id' => 1]);

    $review = BuildDraftReview::run($draft);

    expect($review['header'])->toMatchArray(['seatIndex' => 5, 'seatCount' => 8, 'boosterCatalogId' => 154732, 'picksMade' => 9, 'indecisiveCount' => 1])
        ->and($review['header']['colorsPicked'])->toMatchArray(['W' => 1, 'U' => 1])
        ->and($review['picks'])->toHaveCount(9);

    $first = $review['picks'][0];
    expect($first->label)->toBe('P1p1')
        ->and($first->pickedCatalogId)->toBe(8)
        ->and($first->elapsedSeconds)->toBe(26)
        ->and($first->marginSeconds)->toBe(43)
        ->and($first->indecisive)->toBeTrue()
        ->and($first->reservations)->toBe([['catalogId' => 3, 'atSeconds' => 5], ['catalogId' => 8, 'atSeconds' => 17]])
        ->and($first->wheelReturnOrdinal)->toBe(9)
        ->and($first->wheeledIds)->toBe([1, 3])
        ->and($first->takenIds)->toBe([2])
        ->and($first->note)->toBe('Bard is a bomb');

    expect($review['cards'])->toHaveKeys(['1', '2', '3', '8', '102', '108'])
        ->and($review['cards']['8']->name)->toBe('Bard')
        ->and($review['cards']['2']->name)->toBe('#2')
        ->and($review['signals'][0]['color'])->toBe('U');
});

it('leaves wheel data empty for a pack that never comes back and reports the draft duration', function () {
    $draft = Draft::factory()->create([
        'seat_count' => 8,
        'started_at' => Carbon::parse('2026-08-22 11:00:00'),
        'ended_at' => Carbon::parse('2026-08-22 11:24:00'),
    ]);
    DraftPick::factory()->create([
        'draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'pack_id' => 700, 'cards_available' => [11, 12], 'picked_catalog_id' => 11,
    ]);
    DraftPick::factory()->create([
        'draft_id' => $draft->id, 'ordinal' => 9, 'pack_number' => 1, 'pick_number' => 9,
        'pack_id' => 999, 'cards_available' => [21], 'picked_catalog_id' => 21,
    ]);

    $review = BuildDraftReview::run($draft);
    $first = $review['picks'][0];

    expect($first->wheelReturnOrdinal)->toBeNull()
        ->and($first->wheeledIds)->toBe([])
        ->and($first->takenIds)->toBe([])
        ->and($review['header']['durationMinutes'])->toBe(24)
        ->and($review['header']['avgMarginSeconds'])->toBeNull();
});

it('drops reservations without a usable catalog id and never resolves them as id zero', function () {
    $draft = Draft::factory()->create(['seat_count' => 8]);
    $shown = Carbon::parse('2026-08-22 11:00:00');
    DraftPick::factory()->create([
        'draft_id' => $draft->id, 'ordinal' => 1, 'pack_number' => 1, 'pick_number' => 1,
        'cards_available' => [31], 'picked_catalog_id' => 31, 'shown_at' => $shown,
        'reservations' => [
            ['at' => $shown->copy()->addSeconds(3)->toIso8601String()],
            ['catalog_id' => 31, 'at' => ''],
            ['catalog_id' => 31],
            ['catalog_id' => 31, 'at' => 'not a date'],
        ],
    ]);

    $review = BuildDraftReview::run($draft);

    expect($review['picks'][0]->reservations)->toBe([
        ['catalogId' => 31, 'atSeconds' => null],
        ['catalogId' => 31, 'atSeconds' => null],
        ['catalogId' => 31, 'atSeconds' => null],
    ])->and($review['cards'])->not->toHaveKey('0');
});
