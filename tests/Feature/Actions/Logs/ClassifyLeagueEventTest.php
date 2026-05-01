<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Enums\LogEventType;
use App\Models\LogEvent;

function makeRawEvent(string $rawText): LogEvent
{
    return (new LogEvent)->fill([
        'file_path' => '/tmp/fake.log',
        'byte_offset_start' => 0,
        'byte_offset_end' => strlen($rawText),
        'timestamp' => '00:00:00',
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => $rawText,
        'ingested_at' => now(),
        'logged_at' => now(),
    ]);
}

it('classifies FlsLeagueUserDropReqMessage as league_dropped', function () {
    $event = ClassifyLogEvent::run(makeRawEvent(
        '12:28:15 [INF] (DEFAULT|) Send Class: FlsLeagueUserDropReqMessage'
    ));

    expect($event->event_type)->toBe(LogEventType::LEAGUE_DROPPED->value);
});

it('classifies Flip To Details Side as a league panel view', function () {
    $event = ClassifyLogEvent::run(makeRawEvent(
        "12:27:52 [INF] (UI|Flip To Details Side) League\nEventToken=d2050286-53fd-4072-804f-190d6a3c030a\nEventId=10397"
    ));

    expect($event->event_type)->toBe(LogEventType::LEAGUE_JOINED->value);
    expect($event->match_token)->toBe('d2050286-53fd-4072-804f-190d6a3c030a');
    expect($event->match_id)->toBe('10397');
});
