<?php

use App\Actions\Logs\ClassifyLogEvent;
use App\Models\LogEvent;

it('classifies league join from GameDetailsView event', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=d2050286-53fd-4072-804f-190d6a3c030a\nEventId=10397\nCurrentState=WotC.MtGO.Client.Model.Play.LeagueEvent.League+LeagueGenericState\nPlayFormatCd=Modern\nGameStructureCd= Modern\nJoinedToGame=False",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('league_joined');
    expect($result->match_token)->toBe('d2050286-53fd-4072-804f-190d6a3c030a');
    expect($result->match_id)->toBe('10397');
});

it('does not classify non-league GameDetailsView events', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) Tournament\nEventToken=abc-123\nEventId=99999",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBeNull();
});

it('extracts format from league join event', function () {
    $event = new LogEvent([
        'raw_text' => "12:24:23 [INF] (UI|Creating GameDetailsView) League\nEventToken=abc-def\nEventId=12345\nPlayFormatCd=CPauper\nGameStructureCd= Pauper",
        'context' => 'Creating GameDetailsView',
        'category' => 'UI',
    ]);

    $result = ClassifyLogEvent::run($event);

    expect($result->event_type)->toBe('league_joined');
    expect($result->match_token)->toBe('abc-def');
    expect($result->match_id)->toBe('12345');
});
