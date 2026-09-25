<?php

use App\Actions\Leagues\ProcessLeagueEvents;
use App\Enums\LeagueState;
use App\Facades\AppSettings;
use App\Models\GameEvent;
use App\Models\GameFieldDiff;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-left-'.uniqid();
    mkdir($this->dir);
    AppSettings::setSidecarDirectory($this->dir);
    SidecarSnapshotFactory::attachedStatus($this->dir);
    SidecarAuthorityFlags::applyRemote(['league_drop' => true]);
});

function sidecarLogDrop(array $overrides = []): LogEvent
{
    return LogEvent::create(array_merge([
        'log_instance_id' => LogInstance::factory()->create()->id,
        'file_path' => '/test/log.txt',
        'byte_offset_start' => rand(1, 999999),
        'byte_offset_end' => rand(1, 999999),
        'timestamp' => now(),
        'level' => 'INF',
        'category' => 'DEFAULT',
        'context' => '',
        'raw_text' => '12:30:00 [INF] (DEFAULT|) Send Class: FlsLeagueUserDropReqMessage',
        'event_type' => 'league_dropped',
        'ingested_at' => now(),
        'logged_at' => now(),
    ], $overrides));
}

it('drops the exact league named by the sidecar', function () {
    $modern = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $pauper = League::factory()->create(['event_id' => 20000, 'token' => 'pauper-token']);
    sidecarLogDrop();
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));

    ProcessLeagueEvents::run();

    expect($modern->fresh()->state)->toBe(LeagueState::Dropped)
        ->and($modern->fresh()->dropped_at)->not->toBeNull()
        ->and($pauper->fresh()->state)->toBe(LeagueState::Active);
});

it('completes the league when nothing remains', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 0]));

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Complete);
});

it('finds a league without an event id by token and stamps the event id', function () {
    $league = League::factory()->create(['event_id' => null, 'token' => 'league-token-123']);
    sidecarLogDrop();
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 2]));

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Dropped)
        ->and($league->fresh()->event_id)->toBe(10983);
});

it('consumes the pending log drop so it cannot drop another league', function () {
    $modern = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $other = League::factory()->create(['event_id' => 30000, 'token' => 'other']);
    $drop = sidecarLogDrop();
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));

    ProcessLeagueEvents::run();

    expect($drop->fresh()->processed_at)->not->toBeNull()
        ->and($other->fresh()->state)->toBe(LeagueState::Active)
        ->and($modern->fresh()->state)->toBe(LeagueState::Dropped);
});

it('leaves the log drop pending inside the window, then lets the log decide', function () {
    $drop = sidecarLogDrop();

    ProcessLeagueEvents::run();
    expect($drop->fresh()->processed_at)->toBeNull();

    $this->travel(6)->seconds();
    ProcessLeagueEvents::run();
    expect($drop->fresh()->processed_at)->not->toBeNull();
});

it('reverts a wrong log drop and records the disagreement', function () {
    $wrong = League::factory()->create(['event_id' => 30000, 'token' => 'other']);
    $right = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $match = MtgoMatch::factory()->create(['league_id' => $wrong->id]);
    $loggedAt = now()->subSeconds(2)->startOfSecond();
    sidecarLogDrop(['logged_at' => $loggedAt, 'processed_at' => now()]);
    $wrong->update(['state' => LeagueState::Dropped, 'dropped_at' => $loggedAt]);
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));

    ProcessLeagueEvents::run();

    expect($wrong->fresh()->state)->toBe(LeagueState::Active)
        ->and($wrong->fresh()->dropped_at)->toBeNull()
        ->and($right->fresh()->state)->toBe(LeagueState::Dropped)
        ->and(GameFieldDiff::where('field', 'league_drop')->where('match_id', $match->id)->exists())->toBeTrue();
});

it('ignores leaves with unknown counters, unverified events, and joins', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => null]));
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]), verified: false);
    GameEvent::factory()->create(['type' => 'league_joined', 'match_mtgo_id' => null, 'game_mtgo_id' => null, 'data' => ['league' => SidecarSnapshotFactory::league()]]);

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Active)
        ->and(GameEvent::whereNull('processed_at')->count())->toBe(0);
});

it('does nothing with sidecar events when the flag is off', function () {
    SidecarAuthorityFlags::applyRemote(['league_drop' => false]);
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('queries no sidecar table when the sidecar directory is absent', function () {
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/nope-'.uniqid());
    SidecarAuthorityFlags::applyRemote(['league_drop' => false]);
    DB::enableQueryLog();

    ProcessLeagueEvents::run();

    $sidecarQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'game_events'));
    expect($sidecarQueries)->toBeEmpty();
});

it('does not drop a league on a leave with no drop signal in the log (logout, MTGO closing)', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));
    $this->travel(6)->seconds();

    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('survives a malformed league payload without stalling the pipeline', function () {
    GameEvent::factory()->create(['type' => 'league_left', 'match_mtgo_id' => null, 'game_mtgo_id' => null, 'data' => ['league' => 'garbage']]);

    ProcessLeagueEvents::run();

    expect(GameEvent::whereNull('processed_at')->count())->toBe(0);
});

it('waits for the log drop line when the leave arrives first, then drops the named league', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $leave = SidecarSnapshotFactory::leagueLeft(SidecarSnapshotFactory::league(['matches_remaining' => 3]));

    ProcessLeagueEvents::run();
    expect($leave->fresh()->processed_at)->toBeNull()
        ->and($league->fresh()->state)->toBe(LeagueState::Active);

    sidecarLogDrop();
    ProcessLeagueEvents::run();

    expect($league->fresh()->state)->toBe(LeagueState::Dropped)
        ->and($leave->fresh()->processed_at)->not->toBeNull();
});
