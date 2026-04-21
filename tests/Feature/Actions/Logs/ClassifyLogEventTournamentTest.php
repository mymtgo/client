<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeLogEvent(string $rawText, string $category = 'Tournament', string $context = ''): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => $category,
        'context' => $context,
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);
}

it('classifies EventSyncData_t blocks as tournament_sync', function () {
    $raw = '12:34:56 [INF] (Tournament|Sync) EventSyncData_t in TournamentUninitializedState {"Token":"4b92a89a-a319-4725-aa5a-35bff1357ec9","Foo":1}';

    $event = ClassifyLogEvent::run(makeLogEvent($raw));

    expect($event->event_type)->toBe(LogEventType::TOURNAMENT_SYNC->value);
    expect($event->tournament_token)->toBe('4b92a89a-a319-4725-aa5a-35bff1357ec9');
});
