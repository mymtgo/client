<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Jobs\SubmitMatchLogSample;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function txnLogEvent(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'log_instance_id' => LogInstance::factory()->create()->id,
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

function txnJoinRawText(array $meta = []): string
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

it('creates match from join event with transaction wrapping', function () {
    $matchId = '30001';
    $matchToken = 'token-txn-create';

    txnLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($matchToken, $matchId);

    expect($result)->not->toBeNull();
    expect($result->state)->toBe(MatchState::Started);
    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);
});

it('is idempotent under transaction wrapping', function () {
    $matchId = '30002';
    $matchToken = 'token-txn-idempotent';

    txnLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    $first = AdvanceMatchState::run($matchToken, $matchId);
    $second = AdvanceMatchState::run($matchToken, $matchId);

    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);
    expect($first->id)->toBe($second->id);
});

it('does not regress completed matches', function () {
    $match = MtgoMatch::create([
        'mtgo_id' => '30003',
        'token' => 'token-txn-complete',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now()->subHour(),
        'ended_at' => now(),
        'state' => MatchState::Complete,
        'outcome' => 'win',
    ]);

    txnLogEvent([
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    $result = AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($result->state)->toBe(MatchState::Complete);
});

it('handles concurrent match advancement without duplicates', function () {
    // Simulate rapid re-processing (as would happen with schedule + event trigger)
    $matchId = '30004';
    $matchToken = 'token-txn-concurrent';

    txnLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    // Run multiple times rapidly
    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = AdvanceMatchState::run($matchToken, $matchId);
    }

    // Should always be exactly one match
    expect(MtgoMatch::where('mtgo_id', $matchId)->count())->toBe(1);

    // All results should reference the same match
    $ids = array_unique(array_map(fn ($r) => $r->id, $results));
    expect($ids)->toHaveCount(1);
});

it('dispatches SubmitMatchLogSample when a new match is created', function () {
    Queue::fake();

    $matchId = '30005';
    $matchToken = 'token-txn-log-sample';

    txnLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    AdvanceMatchState::run($matchToken, $matchId);

    Queue::assertPushed(SubmitMatchLogSample::class, 1);
    Queue::assertPushed(SubmitMatchLogSample::class, function (SubmitMatchLogSample $job) use ($matchToken) {
        return $job->matchToken === $matchToken
            && $job->matchType !== ''
            && $job->format !== ''
            && str_contains($job->rawText, 'MatchJoinedEventUnderwayState');
    });
});

it('does not dispatch SubmitMatchLogSample when match already exists', function () {
    Queue::fake();

    $matchId = '30006';
    $matchToken = 'token-txn-no-dup-sample';

    txnLogEvent([
        'match_id' => $matchId,
        'match_token' => $matchToken,
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => txnJoinRawText(),
    ]);

    AdvanceMatchState::run($matchToken, $matchId);
    Queue::assertPushed(SubmitMatchLogSample::class, 1);

    AdvanceMatchState::run($matchToken, $matchId);
    Queue::assertPushed(SubmitMatchLogSample::class, 1); // still 1, not 2
});
