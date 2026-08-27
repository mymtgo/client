<?php

use App\Actions\Drafts\ProcessDraftEvents;
use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\LogEventType;
use App\Events\DraftEnded;
use App\Events\DraftPickCommitted;
use App\Events\DraftPickPending;
use App\Models\Draft;
use App\Models\DraftPick;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const HOBBIT_TOKEN = '791bacca-caea-4d88-b6c7-3bc067d412c2';

function processAllDraftEvents(): void
{
    do {
        ProcessDraftEvents::run();
        $pending = LogEvent::whereIn('event_type', LogEventType::draftValues())->whereNull('processed_at')->exists();
    } while ($pending);
}

it('projects the hobbit draft into a draft league with 42 picks', function () {
    Event::fake([DraftPickPending::class, DraftPickCommitted::class, DraftEnded::class]);
    ingestFixtureLog('mtgo_draft_hobbit.log');

    processAllDraftEvents();

    $league = League::where('event_id', 11039)->firstOrFail();
    expect($league->kind)->toBe(LeagueKind::Draft)
        ->and($league->mtgo_course_id)->toBe(35746768)
        ->and($league->state)->toBe(LeagueState::Active)
        ->and($league->started_at->toIso8601String())->toBe('2026-08-22T11:00:12+00:00')
        ->and($league->set_code)->toBe('HOB');

    $draft = Draft::where('draft_token', HOBBIT_TOKEN)->firstOrFail();
    expect($draft->league_id)->toBe($league->id)
        ->and($draft->mtgo_draft_id)->toBe(6781001)
        ->and($draft->seat_count)->toBe(8)
        ->and($draft->seat_index)->toBe(7)
        ->and($draft->booster_catalog_id)->toBe(154732)
        ->and($draft->pack_size)->toBe(14)
        ->and($draft->picks_expected)->toBe(42)
        ->and($draft->state)->toBe(DraftState::Finished)
        ->and($draft->ended_at)->not->toBeNull()
        ->and($draft->picks()->count())->toBe(42)
        ->and($draft->picks()->whereNull('picked_catalog_id')->count())->toBe(0);

    Event::assertDispatchedTimes(DraftPickPending::class, 42);
    Event::assertDispatchedTimes(DraftPickCommitted::class, 42);
    Event::assertDispatchedTimes(DraftEnded::class, 1);
});

it('records pack contents, pick, reservations and timing for P1p1', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    processAllDraftEvents();

    $pick = DraftPick::whereHas('draft', fn ($q) => $q->where('draft_token', HOBBIT_TOKEN))
        ->where('ordinal', 1)->firstOrFail();

    expect($pick->pack_number)->toBe(1)
        ->and($pick->pick_number)->toBe(1)
        ->and($pick->pack_id)->toBe(143682097)
        ->and($pick->direction)->toBe(0)
        ->and($pick->cards_available)->toHaveCount(14)
        ->and($pick->cards_available)->toContain(154228, 154538)
        ->and($pick->picked_catalog_id)->toBe(154228)
        ->and($pick->picked_card_id)->toBe(2138582391)
        ->and(collect($pick->reservations)->pluck('catalog_id')->all())->toBe([154538, 154228])
        ->and($pick->deadline_at->toIso8601String())->toBe('2026-08-22T11:13:09+00:00')
        ->and($pick->picked_at->toIso8601String())->toBe('2026-08-22T11:12:26+00:00')
        ->and($pick->shown_at)->not->toBeNull()
        ->and($pick->note)->toBeNull();
});

it('derives pack and pick numbers from the payload so the wheel lines up', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    processAllDraftEvents();

    $draft = Draft::where('draft_token', HOBBIT_TOKEN)->firstOrFail();
    $p1p9 = $draft->picks()->where('ordinal', 9)->firstOrFail();
    $p2p1 = $draft->picks()->where('ordinal', 15)->firstOrFail();

    expect($p1p9->pack_id)->toBe(143682097)
        ->and($p1p9->cards_available)->toHaveCount(6)
        ->and($p1p9->picked_catalog_id)->toBe(153998)
        ->and($p2p1->pack_number)->toBe(2)
        ->and($p2p1->pick_number)->toBe(1)
        ->and($p2p1->direction)->toBe(1);
});

it('is idempotent and preserves notes across reprocessing', function () {
    Event::fake([DraftPickPending::class, DraftPickCommitted::class, DraftEnded::class]);
    ingestFixtureLog('mtgo_draft_hobbit.log');
    processAllDraftEvents();

    $draft = Draft::where('draft_token', HOBBIT_TOKEN)->firstOrFail();
    $draft->picks()->where('ordinal', 3)->update(['note' => 'Bilbo over Flock']);

    LogEvent::whereIn('event_type', LogEventType::draftValues())->update(['processed_at' => null]);
    processAllDraftEvents();

    expect(Draft::count())->toBe(1)
        ->and(League::count())->toBe(1)
        ->and($draft->picks()->count())->toBe(42)
        ->and($draft->picks()->where('ordinal', 3)->value('note'))->toBe('Bilbo over Flock');

    Event::assertDispatchedTimes(DraftPickCommitted::class, 42);
    Event::assertDispatchedTimes(DraftPickPending::class, 42);
    Event::assertDispatchedTimes(DraftEnded::class, 1);

    expect(count($draft->picks()->where('ordinal', 1)->first()->reservations))->toBe(2);
});

it('projects the incomplete fixture as a finished draft with no matches', function () {
    ingestFixtureLog('mtgo_draft_incomplete.log', '2026-07-09');
    processAllDraftEvents();

    $league = League::where('event_id', 10814)->firstOrFail();
    $draft = $league->draft;

    expect($league->kind)->toBe(LeagueKind::Draft)
        ->and($league->mtgo_course_id)->toBe(35460131)
        ->and($draft->state)->toBe(DraftState::Finished)
        ->and($draft->picks()->count())->toBe(42)
        ->and($league->matches()->count())->toBe(0)
        ->and($league->set_code)->toBe('MSH');
});

it('fills gaps from the ended message when pending picks were missed', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    LogEvent::where('event_type', LogEventType::DRAFT_PENDING_PICK->value)
        ->orderBy('id')->limit(20)->delete();

    processAllDraftEvents();

    $draft = Draft::where('draft_token', HOBBIT_TOKEN)->firstOrFail();
    expect($draft->picks()->count())->toBe(42)
        ->and($draft->picks()->where('ordinal', 1)->value('picked_catalog_id'))->toBe(154228)
        ->and($draft->picks()->where('ordinal', 1)->value('cards_available'))->toBe([154228])
        ->and($draft->picks()->where('ordinal', 21)->value('cards_available'))->not->toBe([154228]);
});

it('creates the draft from a pending pick when the standing was missed', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');
    LogEvent::whereIn('event_type', [LogEventType::DRAFT_LEAGUE_STANDING->value, LogEventType::DRAFT_JOINED->value, LogEventType::LEAGUE_POOL_GRANTED->value])->delete();

    processAllDraftEvents();

    $draft = Draft::where('draft_token', HOBBIT_TOKEN)->firstOrFail();
    expect($draft->league_id)->toBeNull()
        ->and($draft->picks()->count())->toBe(42);
});

it('processes at most the configured number of events per tick', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    ProcessDraftEvents::run(10);

    expect(LogEvent::whereIn('event_type', LogEventType::draftValues())->whereNotNull('processed_at')->count())->toBe(10);
});

it('returns the number of events processed, and 0 once the backlog is drained', function () {
    ingestFixtureLog('mtgo_draft_hobbit.log');

    expect(ProcessDraftEvents::run(10))->toBe(10);

    do {
        $processed = ProcessDraftEvents::run();
    } while ($processed >= ProcessDraftEvents::BATCH);

    expect(ProcessDraftEvents::run())->toBe(0);
});
