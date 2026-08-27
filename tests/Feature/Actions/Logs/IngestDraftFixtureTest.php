<?php

use App\Enums\LogEventType;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ingests every draft signal from the hobbit fixture', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    $counts = LogEvent::query()
        ->whereIn('event_type', [...LogEventType::draftValues(), LogEventType::MATCH_DECK_REGISTERED->value])
        ->get()
        ->countBy('event_type');

    expect($counts[LogEventType::DRAFT_LEAGUE_STANDING->value])->toBe(1)
        ->and($counts[LogEventType::DRAFT_JOINED->value])->toBe(1)
        ->and($counts[LogEventType::DRAFT_POD_STATE->value])->toBe(1)
        ->and($counts[LogEventType::DRAFT_PACK_OPENED->value])->toBe(3)
        ->and($counts[LogEventType::DRAFT_PENDING_PICK->value])->toBe(42)
        ->and($counts[LogEventType::DRAFT_SELECTION->value])->toBe(130)
        ->and($counts[LogEventType::DRAFT_PICK_COMMITTED->value])->toBe(42)
        ->and($counts[LogEventType::DRAFT_ENDED->value])->toBe(1)
        ->and($counts[LogEventType::LEAGUE_POOL_GRANTED->value])->toBe(1)
        ->and($counts[LogEventType::MATCH_DECK_REGISTERED->value])->toBe(5);

    expect(LogEvent::where('event_type', LogEventType::DRAFT_PENDING_PICK->value)->whereNull('draft_token')->count())->toBe(0);
});

it('captures the pre-match league panel with the draft set format', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    $panel = LogEvent::where('event_type', 'league_joined')
        ->where('match_id', 11039)
        ->where('raw_text', 'like', '%PlayFormatCd=HOBx3%')
        ->first();

    expect($panel)->not->toBeNull();
});
