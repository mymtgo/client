<?php

use App\Actions\Matches\AbandonStaleMatches;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Window;

uses(RefreshDatabase::class);

beforeEach(function () {
    AppSettings::setSystemTimezone('UTC');
});

/**
 * Seed a decoded game-log line as a game_management_json MetaMessage event,
 * stale (90 minutes old) so it sits past the abandon cutoff.
 */
function abandonMetaMessage(string $token, string $mtgoId, LogInstance $instance, string $text, int $sec): void
{
    $textBytes = array_map('ord', str_split($text));
    $len = strlen($text);
    $bytes = array_merge(
        [$len + 24, 0, 0, 0, 3, 17, 186, 129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [$len, 0, 0, 0],
        $textBytes
    );

    LogEvent::create([
        'log_instance_id' => $instance->id,
        'file_path' => '/tmp/abandon.log',
        'byte_offset_start' => $sec * 100,
        'byte_offset_end' => $sec * 100 + 1,
        'timestamp' => sprintf('10:00:%02d', $sec),
        'level' => 'Info',
        'category' => 'Match',
        'context' => 'Processing',
        'ingested_at' => now()->subMinutes(90),
        'logged_at' => now()->subMinutes(90),
        'processed_at' => null,
        'match_token' => $token,
        'match_id' => $mtgoId,
        'game_id' => 999,
        'event_type' => 'game_management_json',
        'raw_text' => '02:00:'.str_pad((string) $sec, 2, '0', STR_PAD_LEFT).' [INF] (Game Management|Processing) Message: {"MatchToken":"'.$token.'","MatchID":'.$mtgoId.',"GameID":999,"MetaMessage":['.implode(',', $bytes).']}',
    ]);
}

function stuckMatch(string $token, string $mtgoId, MatchState $state = MatchState::InProgress): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => $mtgoId,
        'token' => $token,
        'format' => 'Modern',
        'match_type' => 'Swiss',
        'started_at' => now()->subHours(3),
        'state' => $state,
    ]);
}

function stateChangeEvent(string $token, string $mtgoId, string $context, Carbon $loggedAt, ?Carbon $processedAt = null): void
{
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/tmp/abandon.log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 2,
        'timestamp' => $loggedAt->format('H:i:s'),
        'level' => 'Info',
        'category' => 'Match',
        'context' => $context,
        'raw_text' => '(Game Management|'.$context.')',
        'ingested_at' => $loggedAt,
        'logged_at' => $loggedAt,
        'processed_at' => $processedAt,
        'match_token' => $token,
        'match_id' => $mtgoId,
        'event_type' => 'match_state_changed',
    ]);
}

it('marks a stale in_progress match with no end signal as abandoned', function () {
    $match = stuckMatch('tok-a', '1');
    stateChangeEvent('tok-a', '1', 'Match State Changed for tok-a from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: now());

    AbandonStaleMatches::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Abandoned);
    expect($match->ended_at)->not->toBeNull();
});

it('leaves an in_progress match with recent activity alone', function () {
    $match = stuckMatch('tok-b', '2');
    stateChangeEvent('tok-b', '2', 'Match State Changed for tok-b from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(5), processedAt: now());

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::InProgress);
});

it('does not abandon a stale match that already carries an end signal', function () {
    $match = stuckMatch('tok-c', '3');
    stateChangeEvent('tok-c', '3', 'Match State Changed for tok-c from MatchJoinedCompletedState to MatchClosedState', now()->subMinutes(90));

    AbandonStaleMatches::run();

    // Resolvable by reprocessing (ProcessMatchEvents), so the reaper must not touch it.
    expect($match->refresh()->state)->toBe(MatchState::InProgress);
});

it('ignores matches that are not in_progress', function () {
    $match = stuckMatch('tok-d', '4', MatchState::Complete);
    stateChangeEvent('tok-d', '4', 'Match State Changed for tok-d from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: now());

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Complete);
});

it('marks the abandoned match unprocessed events as processed to stop rediscovery', function () {
    $match = stuckMatch('tok-e', '5');
    stateChangeEvent('tok-e', '5', 'Match State Changed for tok-e from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: null);

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);
    expect(LogEvent::where('match_token', 'tok-e')->whereNull('processed_at')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Disconnect-terminated stale matches: survivor wins the match (direction-aware)
// ─────────────────────────────────────────────────────────────────────────────

it('resolves a stale match as a win when the opponent disconnected last', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = stuckMatch('tok-dc-win', '10');
    $instance = LogInstance::factory()->create();
    abandonMetaMessage('tok-dc-win', '10', $instance, '@Panticloser rolled a 5.', 1);
    abandonMetaMessage('tok-dc-win', '10', $instance, '@Pjanrepuge rolled a 1.', 2);
    abandonMetaMessage('tok-dc-win', '10', $instance, '@Pjanrepuge has lost connection to the game.', 3);

    AbandonStaleMatches::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Win);
    expect($match->ended_at)->not->toBeNull();
});

it('resolves a stale match as a loss when the local player disconnected last', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = stuckMatch('tok-dc-loss', '11');
    $instance = LogInstance::factory()->create();
    abandonMetaMessage('tok-dc-loss', '11', $instance, '@Panticloser rolled a 5.', 1);
    abandonMetaMessage('tok-dc-loss', '11', $instance, '@Pjanrepuge rolled a 1.', 2);
    abandonMetaMessage('tok-dc-loss', '11', $instance, '@Panticloser has lost connection to the game.', 3);

    AbandonStaleMatches::run();

    $match->refresh();
    expect($match->state)->toBe(MatchState::Complete);
    expect($match->outcome)->toBe(MatchOutcome::Loss);
});

it('abandons (does not resolve) when the last action is not a disconnect', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn('anticloser');

    $match = stuckMatch('tok-dc-none', '12');
    $instance = LogInstance::factory()->create();
    abandonMetaMessage('tok-dc-none', '12', $instance, '@Panticloser rolled a 5.', 1);
    abandonMetaMessage('tok-dc-none', '12', $instance, '@Pjanrepuge rolled a 1.', 2);
    abandonMetaMessage('tok-dc-none', '12', $instance, '@Pjanrepuge has lost connection to the game.', 3);
    // A real result lands after the disconnect — the disconnect was not the last action.
    abandonMetaMessage('tok-dc-none', '12', $instance, '@Panticloser wins the game.', 4);

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);
});

it('abandons when the local username cannot be resolved', function () {
    Mtgo::shouldReceive('resolveUsername')->andReturn(null);

    $match = stuckMatch('tok-dc-noname', '13');
    $instance = LogInstance::factory()->create();
    abandonMetaMessage('tok-dc-noname', '13', $instance, '@Panticloser rolled a 5.', 1);
    abandonMetaMessage('tok-dc-noname', '13', $instance, '@Pjanrepuge rolled a 1.', 2);
    abandonMetaMessage('tok-dc-noname', '13', $instance, '@Pjanrepuge has lost connection to the game.', 3);

    AbandonStaleMatches::run();

    expect($match->refresh()->state)->toBe(MatchState::Abandoned);
});

it('closes the game overlay when a stale match is abandoned', function () {
    AppSettings::setShowGameOverlay(true);
    Window::fake()->alwaysReturnWindows([
        new Native\Desktop\Windows\Window('main'),
        new Native\Desktop\Windows\Window('game-overlay'),
    ]);

    stuckMatch('tok-overlay', '14');
    stateChangeEvent('tok-overlay', '14', 'Match State Changed for tok-overlay from MatchJoinedEventUnderwayState to MatchJoinedSideboardingState', now()->subMinutes(90), processedAt: now());

    AbandonStaleMatches::run();

    Window::assertClosed('game-overlay');
});
