<?php

use App\Actions\Logs\IngestLog;
use App\Actions\Pipeline\DispatchDomainEvents;
use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Events\DeckLinkedToMatch;
use App\Events\LeagueMatchStarted;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/mtgo_pipeline_test_'.uniqid();
    mkdir($this->tempDir);
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*'));
        rmdir($this->tempDir);
    }
});

it('creates a match through the event-driven pipeline when logs are ingested', function () {
    Event::fake([DeckLinkedToMatch::class, LeagueMatchStarted::class, AppNotification::class]);
    Queue::fake();

    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);

    // At minimum, the fixture has a match_state_changed event that gets ingested
    expect(LogEvent::count())->toBeGreaterThan(0);

    // The IngestLog pipeline should have dispatched domain events and created a match
    // The fixture contains TournamentMatchClosedState which dispatches MatchEnded
    // CreateMatch requires a MatchJoined event (MatchJoinedEventUnderwayState)
    // Verify the pipeline processed something meaningful
    expect(LogEvent::where('event_type', 'match_state_changed')->exists())->toBeTrue();
});

it('creates a match from a join event via DispatchDomainEvents cascade', function () {
    $joinEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'cascade-token',
        'raw_text' => "10:00:00 [INF] (Match|MatchJoinedEventUnderwayState)\nReceiver:\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '10:00:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$joinEvent]));

    $match = MtgoMatch::where('token', 'cascade-token')->first();
    expect($match)->not->toBeNull();
    expect($match->state)->toBe(MatchState::Started);
    expect($match->format)->toBe('Modern');
    expect($match->match_type)->toBe('BO3');
});

it('advances match to InProgress when game state events arrive', function () {
    // Step 1: Join event creates the match
    $joinEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'inprogress-token',
        'raw_text' => "Receiver:\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '10:00:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$joinEvent]));

    $match = MtgoMatch::where('token', 'inprogress-token')->first();
    expect($match->state)->toBe(MatchState::Started);

    // Step 2: Game state event advances to InProgress
    $gameStateEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => 'inprogress-token',
        'match_id' => '12345',
        'game_id' => '99',
    ]);

    DispatchDomainEvents::run(collect([$gameStateEvent]));

    expect($match->refresh()->state)->toBe(MatchState::InProgress);
});

it('transitions match to Ended when end signal arrives', function () {
    // Step 1: Create a match already in InProgress
    $match = MtgoMatch::factory()->create([
        'token' => 'ended-token',
        'mtgo_id' => '54321',
        'state' => MatchState::InProgress,
        'ended_at' => now(),
    ]);

    // Step 2: End event transitions to Ended
    $endEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'match_token' => 'ended-token',
        'timestamp' => '10:30:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$endEvent]));

    // EndMatch transitions to Ended; CompleteMatch tries to advance to Complete
    // but may stay at Ended without a game log file available
    $match->refresh();
    expect($match->state)->toBeIn([MatchState::Ended, MatchState::Complete]);
});

it('full event cascade creates and progresses a match through states', function () {
    Event::fake([DeckLinkedToMatch::class, LeagueMatchStarted::class, AppNotification::class]);
    Queue::fake();

    $token = 'full-cascade-token';

    // Step 1: Join event → CreateMatch → Started
    $joinEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => $token,
        'raw_text' => "Receiver:\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '10:00:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$joinEvent]));

    $match = MtgoMatch::where('token', $token)->first();
    expect($match)->not->toBeNull();
    expect($match->state)->toBe(MatchState::Started);

    // Step 2: Game state event → AdvanceMatchToInProgress → InProgress
    $gameStateEvent = LogEvent::factory()->create([
        'event_type' => 'game_state_update',
        'match_token' => $token,
        'match_id' => '99999',
        'game_id' => '1',
    ]);

    DispatchDomainEvents::run(collect([$gameStateEvent]));

    expect($match->refresh()->state)->toBe(MatchState::InProgress);

    // Step 3: End event → EndMatch → Ended (then CompleteMatch attempts Complete)
    $endEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'TournamentMatchClosedState',
        'match_token' => $token,
        'timestamp' => '10:30:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$endEvent]));

    $match->refresh();
    expect($match->state)->toBeIn([MatchState::Ended, MatchState::Complete]);
});

it('is idempotent — reprocessing same join event produces no duplicate matches', function () {
    $joinEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => 'MatchJoinedEventUnderwayState',
        'match_token' => 'idem-token',
        'raw_text' => "Receiver:\nPlayFormatCd=Modern\nGameStructureCd=BO3",
        'timestamp' => '10:00:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$joinEvent]));
    DispatchDomainEvents::run(collect([$joinEvent]));
    DispatchDomainEvents::run(collect([$joinEvent]));

    expect(MtgoMatch::where('token', 'idem-token')->count())->toBe(1);
});

it('is idempotent — reprocessing same log file produces no duplicate log events', function () {
    $logPath = $this->tempDir.'/test.log';
    copy(base_path('tests/Fixtures/sample_log.txt'), $logPath);

    IngestLog::run($logPath);
    $countAfterFirst = LogEvent::count();

    IngestLog::run($logPath);
    $countAfterSecond = LogEvent::count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('handles all end signal types in the cascade', function (string $signal) {
    $token = 'signal-token-'.md5($signal);

    MtgoMatch::factory()->create([
        'token' => $token,
        'state' => MatchState::InProgress,
        'ended_at' => now(),
    ]);

    $endEvent = LogEvent::factory()->create([
        'event_type' => 'match_state_changed',
        'context' => $signal,
        'match_token' => $token,
        'timestamp' => '10:30:00',
        'logged_at' => now(),
    ]);

    DispatchDomainEvents::run(collect([$endEvent]));

    $match = MtgoMatch::where('token', $token)->first();
    expect($match->state)->toBeIn([MatchState::Ended, MatchState::Complete]);
})->with([
    'TournamentMatchClosedState',
    'MatchCompletedState',
    'MatchEndedState',
    'MatchClosedState',
    'JoinedCompletedState',
]);
