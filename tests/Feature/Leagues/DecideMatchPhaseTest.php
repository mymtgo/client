<?php

use App\Actions\Leagues\DecideMatchPhase;
use App\Enums\LogEventType;
use App\Facades\AppSettings;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarTables;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    SidecarTables::reset();
    $this->sidecarDir = sys_get_temp_dir().'/overlay-phase-'.uniqid();
});

afterEach(function () {
    File::deleteDirectory($this->sidecarDir);
    SidecarTables::reset();
});

/** A live sidecar: directory plus a heartbeat written just now. */
function liveSidecar(string $dir, ?CarbonImmutable $heartbeat = null): void
{
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/status.json', json_encode([
        'state' => 'attached',
        'heartbeat' => ($heartbeat ?? CarbonImmutable::now())->toIso8601String(),
    ]));
    AppSettings::setSidecarDirectory($dir);
}

function phaseMatch(): MtgoMatch
{
    return MtgoMatch::factory()->inProgress()->create(['mtgo_id' => '555001', 'started_at' => now()->subMinutes(20)]);
}

/** One sidecar lifecycle row. $session orders sessions; $seq orders within one. */
function lifecycle(MtgoMatch $match, string $type, int $seq, string $sessionStartedAt = '2026-10-01 10:00:00'): GameEvent
{
    return GameEvent::factory()->create([
        'session' => 'sess-'.$sessionStartedAt,
        'session_started_at' => $sessionStartedAt,
        'seq' => $seq,
        'type' => $type,
        'match_mtgo_id' => $match->mtgo_id,
        'game_mtgo_id' => null,
        'data' => [],
    ]);
}

function logSideboardingState(MtgoMatch $match, Carbon\Carbon $at): void
{
    LogEvent::create([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => 'mtgo.log', 'byte_offset_start' => 0, 'byte_offset_end' => 1,
        'timestamp' => $at->format('H:i:s'), 'logged_at' => $at->copy(),
        'level' => 'INF', 'category' => 'Game Management',
        'context' => 'Match State Changed from MatchJoinedGameStartedState to MatchJoinedSideboardingState',
        'raw_text' => 'Match State Changed', 'ingested_at' => now(),
        'match_token' => $match->token, 'event_type' => LogEventType::MATCH_STATE_CHANGED->value,
    ]);
}

it('is sideboarding when the latest sidecar lifecycle row is sideboarding_started', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    lifecycle($match, 'match_started', 1);
    lifecycle($match, 'game_started', 2);
    lifecycle($match, 'game_ended', 3);
    lifecycle($match, 'sideboarding_started', 4);

    expect(DecideMatchPhase::run($match, loggedGameCount: 1))->toBe('sideboarding');
});

it('is in game once game_started follows sideboarding', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    lifecycle($match, 'game_started', 1);
    lifecycle($match, 'sideboarding_started', 2);
    lifecycle($match, 'game_started', 3);

    expect(DecideMatchPhase::run($match, loggedGameCount: 2))->toBe('in_game');
});

it('resolves the real fixture tail where sideboarding ends in match_ended', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    lifecycle($match, 'game_started', 1);
    lifecycle($match, 'sideboarding_started', 2);
    lifecycle($match, 'sideboard_submitted', 3);
    lifecycle($match, 'sideboarding_started', 4);
    lifecycle($match, 'sideboard_submitted', 5);
    lifecycle($match, 'match_ended', 6);

    expect(DecideMatchPhase::run($match, loggedGameCount: 1))->toBe('in_game');
});

it('orders by session then seq, not by seq alone', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    lifecycle($match, 'game_started', 1, '2026-10-01 10:00:00');
    lifecycle($match, 'sideboarding_started', 900, '2026-10-01 10:00:00');
    lifecycle($match, 'game_started', 2, '2026-10-01 11:00:00');

    expect(DecideMatchPhase::run($match, loggedGameCount: 2))->toBe('in_game');
});

it('falls back to the log floor when the log has more games than the sidecar saw', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    lifecycle($match, 'game_started', 1);
    lifecycle($match, 'sideboarding_started', 2);
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18)]);
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(2)]);

    expect(DecideMatchPhase::run($match, loggedGameCount: 2))->toBe('in_game');
});

it('uses the log floor when the heartbeat is stale', function () {
    liveSidecar($this->sidecarDir, CarbonImmutable::now()->subMinutes(5));
    $match = phaseMatch();
    lifecycle($match, 'game_started', 1);
    lifecycle($match, 'sideboarding_started', 2);
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(1)]);

    expect(DecideMatchPhase::run($match, loggedGameCount: 1))->toBe('in_game');
});

it('uses DetectSideboarding as the log floor without a sidecar', function () {
    AppSettings::setSidecarDirectory($this->sidecarDir);
    $match = phaseMatch();
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18), 'ended_at' => now()->subMinutes(5)]);
    logSideboardingState($match, now()->subMinutes(4));

    expect(DecideMatchPhase::run($match, loggedGameCount: 1))->toBe('sideboarding');
});

it('is in game when a game has ended_at but no sideboarding state was logged', function () {
    AppSettings::setSidecarDirectory($this->sidecarDir);
    $match = phaseMatch();
    Game::factory()->create(['match_id' => $match->id, 'started_at' => now()->subMinutes(18), 'ended_at' => now()]);

    expect(DecideMatchPhase::run($match, loggedGameCount: 1))->toBe('in_game');
});

it('never queries game_events without a sidecar directory', function () {
    AppSettings::setSidecarDirectory($this->sidecarDir);
    $match = phaseMatch();

    DB::enableQueryLog();
    DecideMatchPhase::run($match, loggedGameCount: 0);

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'game_events')))->toBeEmpty();
});

it('never queries game_events when the sidecar tables are missing', function () {
    liveSidecar($this->sidecarDir);
    $match = phaseMatch();
    Schema::drop('game_field_diffs');
    Schema::drop('game_events');
    SidecarTables::reset();

    DB::enableQueryLog();
    $phase = DecideMatchPhase::run($match, loggedGameCount: 0);

    expect($phase)->toBe('in_game')
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from "game_events"')))->toBeEmpty();
});
