<?php

use App\Actions\Leagues\CompleteLeagueFromSnapshot;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $dir = sys_get_temp_dir().'/sidecar-complete-'.uniqid();
    mkdir($dir);
    AppSettings::setSidecarDirectory($dir);
});

it('completes a run first seen mid-way when the ended snapshot says it is finished', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => true]);
    $league = League::factory()->create(['event_id' => 10983]);
    $m = MtgoMatch::factory()->create(['league_id' => $league->id, 'format' => 'CMODERN']);
    SidecarSnapshotFactory::snapshot($m, 'ended', SidecarSnapshotFactory::league(['match_number' => 5, 'matches_remaining' => 0]));

    CompleteLeagueFromSnapshot::run($m);

    expect($league->fresh()->state)->toBe(LeagueState::Complete);
});

it('leaves a run that is still going', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => true]);
    $league = League::factory()->create(['event_id' => 10983]);
    $m = MtgoMatch::factory()->create(['league_id' => $league->id, 'format' => 'CMODERN']);
    SidecarSnapshotFactory::snapshot($m, 'ended', SidecarSnapshotFactory::league(['match_number' => 2, 'matches_remaining' => 3]));

    CompleteLeagueFromSnapshot::run($m);

    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('ignores the ended snapshot with the flag off', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => false]);
    $league = League::factory()->create(['event_id' => 10983]);
    $m = MtgoMatch::factory()->create(['league_id' => $league->id, 'format' => 'CMODERN']);
    SidecarSnapshotFactory::snapshot($m, 'ended', SidecarSnapshotFactory::league(['match_number' => 5, 'matches_remaining' => 0]));

    CompleteLeagueFromSnapshot::run($m);

    expect($league->fresh()->state)->toBe(LeagueState::Active);
});

it('completes a full run whose last match only got its league on retry', function () {
    $league = League::factory()->create(['token' => 'league-token-123']);
    MtgoMatch::factory()->count(4)->create(['league_id' => $league->id, 'state' => MatchState::Complete, 'deck_version_id' => null]);
    $last = MtgoMatch::factory()->create(['league_id' => null, 'state' => MatchState::Complete, 'deck_version_id' => null, 'started_at' => now()]);
    LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'match_token' => $last->token,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "12:00:00 [INF] (Game Management|Match State Changed) Receiver:\nLeague Token=league-token-123\nPlayFormatCd=CStandard",
    ]);

    RelinkOrphanMatches::run();

    expect($last->fresh()->league_id)->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Complete);
});
