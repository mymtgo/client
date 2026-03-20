<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LogEventType;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Helper: create a log event with sensible defaults.
 */
function createLogEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => rand(0, 999999),
        'byte_offset_end' => rand(1000000, 9999999),
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => '',
        'raw_text' => '',
        'event_type' => null,
        'logged_at' => now(),
        'match_id' => null,
        'match_token' => null,
        'ingested_at' => now(),
    ], $overrides));
}

/**
 * Helper: build the raw_text that ExtractKeyValueBlock can parse.
 * The parser requires "Receiver:" to be present before the key-value lines.
 */
function buildJoinRawText(array $meta = []): string
{
    $defaults = [
        'PlayFormatCd' => 'Pmodern',
        'GameStructureCd' => 'Constructed',
    ];

    $meta = array_merge($defaults, $meta);

    $lines = ['12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)', 'Receiver:'];

    foreach ($meta as $key => $value) {
        $lines[] = "{$key} = {$value}";
    }

    return implode("\n", $lines);
}

// ─────────────────────────────────────────────────────────────────────────────

it('does not create a match without a join event', function () {
    $matchId = '99999';
    $matchToken = 'token-no-join';

    // Create a state change event that is NOT a join event
    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'SomeOtherState',
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->toBeNull();
    expect(MtgoMatch::where('mtgo_id', $matchId)->exists())->toBeFalse();
});

it('creates a match in started state when join event exists', function () {
    $matchId = '10001';
    $matchToken = 'token-join';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->not->toBeNull();
    expect($result->mtgo_id)->toBe($matchId);
    expect($result->state)->toBe(MatchState::Started);
    expect($result->format)->toBe('Pmodern');
    expect($result->match_type)->toBe('Constructed');
});

it('is idempotent — running twice does not duplicate', function () {
    $matchId = '10002';
    $matchToken = 'token-idempotent';

    createLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $first = AdvanceMatchState::run($matchToken, $matchId);
    $second = AdvanceMatchState::run($matchToken, $matchId);

    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);
    expect($first->id)->toBe($second->id);
});

it('does not regress state', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '10003',
        'token' => 'token-complete',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::Complete,
        'outcome' => MatchOutcome::Win,
    ]);

    // Create a join event so the gate check passes
    createLogEvent([
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => buildJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($result->state)->toBe(MatchState::Complete);
    expect($result->outcome)->toBe(MatchOutcome::Win);
});

it('only shows complete matches in complete scope', function () {
    $states = [
        MatchState::Started,
        MatchState::InProgress,
        MatchState::Ended,
        MatchState::Complete,
        MatchState::Complete,
    ];

    foreach ($states as $i => $state) {
        MtgoMatch::create([
            'mtgo_id' => "scope-{$i}",
            'token' => "scope-token-{$i}",
            'format' => 'Pmodern',
            'match_type' => 'Constructed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'state' => $state,
            'outcome' => MatchOutcome::Unknown,
        ]);
    }

    expect(MtgoMatch::complete()->count())->toBe(2);
    expect(MtgoMatch::incomplete()->count())->toBe(3);
});
