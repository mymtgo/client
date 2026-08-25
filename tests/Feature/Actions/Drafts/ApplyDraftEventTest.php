<?php

use App\Actions\Drafts\ApplyDraftEvent;
use App\Enums\DraftState;
use App\Enums\LogEventType;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A FlsBoosterDraftPickSucceededMessage commit line, the shape ApplyDraftEvent
 * decodes in committed().
 */
function pickSucceededEvent(string $draftToken, int $packId, int $catalogId, int $cardId, string $time): LogEvent
{
    $json = json_encode([
        'DraftToken' => $draftToken,
        'DraftID' => 6781001,
        'Selections' => [['CatalogID' => $catalogId, 'IsReservation' => false]],
        'PicksMade' => [[
            'LoginID' => 3022021,
            'PackID' => $packId,
            'Selections' => [['CardID' => $cardId, 'CatalogID' => $catalogId]],
            'UserSelectedCount' => 1,
            'UserReservedCount' => 0,
            'Time' => $time,
        ]],
        'IsPickComplete' => true,
    ]);

    return LogEvent::factory()->create([
        'event_type' => LogEventType::DRAFT_PICK_COMMITTED->value,
        'draft_token' => $draftToken,
        'raw_text' => "12:12:27 [INF] (Game Management|Processing Registered Handler for FlsBoosterDraftPickSucceededMessage in DraftPickAwaitingValidationState) Message: {$json}",
        'logged_at' => now(),
    ]);
}

it('does not stamp a replayed commit onto an unrelated pending pick', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);

    /** Picks 1 to 8 are committed; pick 9's pack is still on the table. */
    foreach (range(1, 9) as $ordinal) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $ordinal,
            'pack_id' => 143682000 + $ordinal,
            'cards_available' => [154220 + $ordinal, 154538],
            'picked_catalog_id' => $ordinal === 9 ? null : 154220 + $ordinal,
            'picked_card_id' => $ordinal === 9 ? null : 2138582380 + $ordinal,
            'picked_at' => $ordinal === 9 ? null : now()->subMinutes(10 - $ordinal),
        ]);
    }

    $event = pickSucceededEvent($draft->draft_token, 143682005, 154225, 2138582385, '2026-08-22T12:12:26.3150000+01:00');

    ApplyDraftEvent::run($event);

    $pickNine = $draft->picks()->where('ordinal', 9)->first();
    $pickFive = $draft->picks()->where('ordinal', 5)->first();

    expect($pickNine->picked_catalog_id)->toBeNull()
        ->and($pickNine->picked_card_id)->toBeNull()
        ->and($pickNine->picked_at)->toBeNull()
        ->and($pickFive->picked_catalog_id)->toBe(154225)
        ->and($pickFive->picked_card_id)->toBe(2138582385);
});

it('still applies a first-time commit whose card the selection line already recorded', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);

    foreach (range(1, 3) as $ordinal) {
        DraftPick::factory()->for($draft)->create([
            'ordinal' => $ordinal,
            'pack_id' => 143682000 + $ordinal,
            'cards_available' => [154228, 154538],
        ]);
    }

    /** SubmitDraftSelectionsAction lands first and writes the catalog id only. */
    $draft->picks()->where('ordinal', 2)->update([
        'picked_catalog_id' => 154228,
        'picked_at' => now()->subMinute(),
    ]);

    $event = pickSucceededEvent($draft->draft_token, 143682002, 154228, 2138582391, '2026-08-22T12:12:26.3150000+01:00');

    ApplyDraftEvent::run($event);

    $pick = $draft->picks()->where('ordinal', 2)->first();

    expect($pick->picked_card_id)->toBe(2138582391)
        ->and($pick->picked_at->toIso8601String())->toBe('2026-08-22T11:12:26+00:00');
});
