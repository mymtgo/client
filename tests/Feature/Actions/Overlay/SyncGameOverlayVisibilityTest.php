<?php

use App\Actions\Matches\AdvanceMatchState;
use App\Actions\Overlay\SyncGameOverlayVisibility;
use App\Enums\LogEventType;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

uses(RefreshDatabase::class);

function overlayMatch(array $overrides = []): MtgoMatch
{
    return MtgoMatch::create(array_merge([
        'mtgo_id' => (string) rand(100000, 999999),
        'token' => 'overlay-token-'.rand(1000, 9999),
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now(),
        'ended_at' => null,
        'state' => MatchState::InProgress,
    ], $overrides));
}

function fakeOverlayWindowOpen(): void
{
    Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
        new WindowInstance('game-overlay'),
    ]);
}

it('opens the overlay when a match is in progress and the setting is enabled', function () {
    AppSettings::setShowGameOverlay(true);
    overlayMatch();

    SyncGameOverlayVisibility::run();

    Window::assertOpened('game-overlay');
});

it('does not open the overlay when the setting is disabled', function () {
    AppSettings::setShowGameOverlay(false);
    overlayMatch();

    SyncGameOverlayVisibility::run();

    Window::assertOpenedCount(0);
});

it('does not open the overlay for a match that is only started', function () {
    AppSettings::setShowGameOverlay(true);
    overlayMatch(['state' => MatchState::Started]);

    SyncGameOverlayVisibility::run();

    Window::assertOpenedCount(0);
});

it('does not open the overlay for a failed in-progress match', function () {
    AppSettings::setShowGameOverlay(true);
    overlayMatch(['failed_at' => now()]);

    SyncGameOverlayVisibility::run();

    Window::assertOpenedCount(0);
});

it('closes an open overlay when no match is in progress', function () {
    AppSettings::setShowGameOverlay(true);
    fakeOverlayWindowOpen();
    overlayMatch(['state' => MatchState::Ended, 'ended_at' => now()]);

    SyncGameOverlayVisibility::run();

    Window::assertClosed('game-overlay');
});

it('closes an open overlay when the setting is disabled', function () {
    AppSettings::setShowGameOverlay(false);
    fakeOverlayWindowOpen();
    overlayMatch();

    SyncGameOverlayVisibility::run();

    Window::assertClosed('game-overlay');
});

it('closes the overlay when AdvanceMatchState ends the match', function () {
    AppSettings::setShowGameOverlay(true);
    fakeOverlayWindowOpen();

    $match = overlayMatch();

    $logInstanceId = LogInstance::factory()->create()->id;

    LogEvent::create([
        'log_instance_id' => $logInstanceId,
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 1,
        'byte_offset_end' => 100,
        'timestamp' => '12:00:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "12:00:00 [INF] (Match|MatchJoinedEventUnderwayState)\nReceiver:\nPlayFormatCd = Pmodern\nGameStructureCd = Constructed",
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'logged_at' => now(),
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'ingested_at' => now(),
    ]);

    LogEvent::create([
        'log_instance_id' => $logInstanceId,
        'file_path' => '/tmp/test.log',
        'byte_offset_start' => 101,
        'byte_offset_end' => 200,
        'timestamp' => '12:30:00',
        'level' => 'INF',
        'category' => 'Match',
        'context' => 'MatchCompletedState',
        'raw_text' => '12:30:00 [INF] (Match|MatchCompletedState)',
        'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
        'logged_at' => now(),
        'match_id' => $match->mtgo_id,
        'match_token' => $match->token,
        'ingested_at' => now(),
    ]);

    AdvanceMatchState::run($match->token, $match->mtgo_id);

    expect($match->fresh()->state)->toBe(MatchState::Ended);
    Window::assertClosed('game-overlay');
});
