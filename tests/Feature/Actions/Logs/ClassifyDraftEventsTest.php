<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function classifySample(string $file): LogEvent
{
    $raw = rtrim(file_get_contents(base_path("tests/Fixtures/log_samples/{$file}")), "\n");

    return ClassifyLogEvent::run(new LogEvent(['raw_text' => $raw]));
}

const HOBBIT_DRAFT_TOKEN = '791bacca-caea-4d88-b6c7-3bc067d412c2';

it('classifies token-bearing draft lines', function (string $file, LogEventType $type) {
    $event = classifySample($file);

    expect($event->event_type)->toBe($type->value)
        ->and($event->draft_token)->toBe(HOBBIT_DRAFT_TOKEN)
        ->and($event->match_token)->toBeNull();
})->with([
    ['draft_league_standing.txt', LogEventType::DRAFT_LEAGUE_STANDING],
    ['draft_joined.txt', LogEventType::DRAFT_JOINED],
    ['draft_pod_state.txt', LogEventType::DRAFT_POD_STATE],
    ['draft_pack_opened.txt', LogEventType::DRAFT_PACK_OPENED],
    ['draft_pending_pick.txt', LogEventType::DRAFT_PENDING_PICK],
    ['draft_pick_committed.txt', LogEventType::DRAFT_PICK_COMMITTED],
    ['draft_ended.txt', LogEventType::DRAFT_ENDED],
    ['draft_state_changed.txt', LogEventType::DRAFT_STATE_CHANGED],
]);

it('classifies the draft created line by its context token', function () {
    $raw = '12:11:59 [INF] (Game Management|Draft Created: 791bacca-caea-4d88-b6c7-3bc067d412c2) Details: Tournament Data:';
    $event = ClassifyLogEvent::run(new LogEvent(['raw_text' => $raw]));

    expect($event->event_type)->toBe(LogEventType::DRAFT_CREATED->value)
        ->and($event->draft_token)->toBe(HOBBIT_DRAFT_TOKEN);
});

it('classifies token-less draft selection lines', function (string $file) {
    $event = classifySample($file);

    expect($event->event_type)->toBe(LogEventType::DRAFT_SELECTION->value)
        ->and($event->draft_token)->toBeNull();
})->with(['draft_selection_reserved.txt', 'draft_selection_committed.txt']);

it('ignores reservation-only pick acknowledgements', function () {
    $event = classifySample('draft_pick_reservation_ack.txt');

    expect($event->event_type)->toBeNull();
});

it('classifies the pool grant block', function () {
    $event = classifySample('league_pool_granted.txt');

    expect($event->event_type)->toBe(LogEventType::LEAGUE_POOL_GRANTED->value)
        ->and($event->draft_token)->toBeNull();
});

it('classifies the registered deck response with its match token', function () {
    $event = classifySample('match_deck_registered.txt');

    expect($event->event_type)->toBe(LogEventType::MATCH_DECK_REGISTERED->value)
        ->and($event->match_token)->toBe('c0d8ff76-dc13-480b-bdf0-a228a6cd20f8')
        ->and($event->match_id)->toBe(289328158);
});

it('classifies the deck building panel as a league panel view', function () {
    $event = classifySample('league_deck_building_panel.txt');

    expect($event->event_type)->toBe('league_joined')
        ->and($event->match_token)->toBe('48a2e914-f2ee-4fce-a4ad-47e396488889')
        ->and((int) $event->match_id)->toBe(11039);
});

it('still classifies a plain game management json line', function () {
    $raw = '12:29:20 [INF] (Game Management|Processing Registered Handler for GsMessageMessage in LeagueMatchJoinedEventUnderwayState) Processor: X Message: {"MatchToken":"08efb7ad-3670-4fab-b015-97cea823cde1","MatchID":289328482,"GameID":959763634} Receiver: Y';
    $event = ClassifyLogEvent::run(new LogEvent(['raw_text' => $raw]));

    expect($event->event_type)->toBe('game_management_json')
        ->and($event->match_token)->toBe('08efb7ad-3670-4fab-b015-97cea823cde1');
});
