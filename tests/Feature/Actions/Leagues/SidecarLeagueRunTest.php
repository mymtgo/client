<?php

use App\Actions\Leagues\ResolveLeagueRunFromSidecar;
use App\Actions\Matches\AssignLeague;
use App\Actions\Sidecar\ApplySidecarProjection;
use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\DeckVersion;
use App\Models\GameFieldDiff;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\SidecarSnapshotFactory;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-run-'.uniqid();
    mkdir($this->dir);
    AppSettings::setSidecarDirectory($this->dir);
    SidecarAuthorityFlags::applyRemote(['league_run' => true]);
    $this->meta = ['League Token' => 'league-token-123', 'PlayFormatCd' => 'CMODERN', 'GameStructureCd' => 'Modern'];
    $this->dv = DeckVersion::factory()->create();
    $this->newMatch = fn (array $attrs = []) => MtgoMatch::factory()->create(array_merge([
        'state' => MatchState::InProgress, 'format' => 'CMODERN', 'deck_version_id' => $this->dv->id, 'started_at' => now(),
    ], $attrs));
    $this->joinedState = fn (MtgoMatch $m, string $token = 'league-token-123') => LogEvent::factory()->create([
        'event_type' => 'game_management_json',
        'match_token' => $m->token,
        'context' => 'MatchJoinedEventUnderwayState',
        'raw_text' => "12:00:00 [INF] (Game Management|Match State Changed) Receiver:\nLeague Token={$token}\nPlayFormatCd=CMODERN",
    ]);
});

it('mints the first match of a run and stamps the event id', function () {
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    AssignLeague::run($m, $this->meta);

    $league = $m->fresh()->league;
    expect($league->event_id)->toBe(10983)
        ->and($league->token)->toBe('league-token-123')
        ->and($league->deck_version_id)->toBe($this->dv->id)
        ->and($league->state)->toBe(LeagueState::Active);
});

it('keeps a run together across a deck version change', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'deck_version_id' => $this->dv->id]);
    ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    $m2 = ($this->newMatch)(['deck_version_id' => DeckVersion::factory()->create()->id]);
    SidecarSnapshotFactory::snapshot($m2, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));

    AssignLeague::run($m2, $this->meta);

    expect($m2->fresh()->league_id)->toBe($run->id)
        ->and(League::count())->toBe(1);
});

it('splits an unwatched same-deck re-entry', function () {
    $old = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'deck_version_id' => $this->dv->id]);
    ($this->newMatch)(['league_id' => $old->id, 'mtgo_id' => '100']);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 3, 'game_history' => [['match_id' => 200], ['match_id' => 201]]]));

    AssignLeague::run($m, $this->meta);

    expect($old->fresh()->state)->toBe(LeagueState::Partial)
        ->and($m->fresh()->league_id)->not->toBe($old->id);
});

it('backfills local history matches on mint', function () {
    $earlier = ($this->newMatch)(['mtgo_id' => '100', 'state' => MatchState::Complete]);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));

    AssignLeague::run($m, $this->meta);

    expect($earlier->fresh()->league_id)->toBe($m->fresh()->league_id);
});

it('never backfills a manual match', function () {
    $manual = ($this->newMatch)(['mtgo_id' => '100', 'state' => MatchState::Complete, 'manual' => true]);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));

    AssignLeague::run($m, $this->meta);

    expect($manual->fresh()->league_id)->toBeNull();
});

it('corrects a log split late and removes the emptied log-minted league', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Partial]);
    ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    $logMinted = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $m = ($this->newMatch)(['league_id' => $logMinted->id]);
    SidecarSnapshotFactory::matchStarted($m);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));
    ($this->joinedState)($m);

    ApplySidecarProjection::run($m->fresh());

    expect($m->fresh()->league_id)->toBe($run->id)
        ->and($run->fresh()->state)->toBe(LeagueState::Active)
        ->and(League::find($logMinted->id))->toBeNull()
        ->and(GameFieldDiff::where('field', 'league_run')->exists())->toBeTrue();
});

it('reuses the solo log-minted league instead of minting a second one', function () {
    $logMinted = League::factory()->create(['event_id' => null, 'token' => 'league-token-123', 'state' => LeagueState::Partial]);
    $m = ($this->newMatch)(['league_id' => $logMinted->id]);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    expect(ResolveLeagueRunFromSidecar::run($m, $this->meta))->toBeTrue();

    expect(League::count())->toBe(1)
        ->and($m->fresh()->league_id)->toBe($logMinted->id)
        ->and($logMinted->fresh()->state)->toBe(LeagueState::Active)
        ->and($logMinted->fresh()->event_id)->toBe(10983);
});

it('re-running on a correctly attached match changes nothing', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $m = ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    expect(ResolveLeagueRunFromSidecar::run($m, $this->meta))->toBeTrue()
        ->and(ResolveLeagueRunFromSidecar::run($m->fresh(), $this->meta))->toBeTrue();

    expect($m->fresh()->league_id)->toBe($run->id)
        ->and(League::count())->toBe(1)
        ->and($run->fresh()->state)->toBe(LeagueState::Active)
        ->and(GameFieldDiff::count())->toBe(0);
});

it('lets the log decide on a token mismatch, and records it', function () {
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['token' => 'some-other-token']));

    AssignLeague::run($m, $this->meta);

    expect($m->fresh()->league->token)->toBe('league-token-123')
        ->and(GameFieldDiff::where('field', 'league_run')->exists())->toBeTrue();
});

it('lets the log decide when the joined-state log line is gone', function () {
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    expect(ResolveLeagueRunFromSidecar::run($m))->toBeFalse();
});

it('never touches submitted, manual or limited matches, or manual leagues', function (array $attrs) {
    $manualLeague = League::factory()->manual()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $m = ($this->newMatch)($attrs);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    expect(ResolveLeagueRunFromSidecar::run($m, $this->meta))->toBeFalse()
        ->and($manualLeague->fresh()->state)->toBe(LeagueState::Complete);
})->with([
    'submitted' => [['submitted_at' => now()]],
    'manual' => [['manual' => true]],
    'limited' => [['format' => 'DMKM']],
]);

it('defers while the deck is still deferred, then decides', function () {
    SidecarSnapshotFactory::attachedStatus($this->dir);
    SidecarAuthorityFlags::applyRemote(['league_run' => false, 'match_deck' => true]);
    $m = ($this->newMatch)(['deck_version_id' => null]);

    AssignLeague::run($m, $this->meta);
    expect($m->fresh()->league_id)->toBeNull();

    $this->travel(6)->seconds();
    AssignLeague::run($m->fresh(), $this->meta);
    expect($m->fresh()->league_id)->not->toBeNull();
});

it('does nothing with the flag off', function () {
    SidecarAuthorityFlags::applyRemote(['league_run' => false]);
    $old = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'deck_version_id' => $this->dv->id]);
    ($this->newMatch)(['league_id' => $old->id, 'mtgo_id' => '100']);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 3, 'game_history' => [['match_id' => 200]]]));

    AssignLeague::run($m, $this->meta);

    // today's log behaviour: same token and deck glue onto the old run
    expect($m->fresh()->league_id)->toBe($old->id);
});

/*
| Final review fixes
*/

it('does not split a run that completed normally when its last match is re-projected', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    foreach (range(1, 4) as $i) {
        ($this->newMatch)(['league_id' => $league->id, 'mtgo_id' => (string) (100 + $i), 'state' => MatchState::Complete]);
    }
    $last = ($this->newMatch)(['league_id' => $league->id, 'mtgo_id' => '105', 'state' => MatchState::Complete]);
    $league->update(['state' => LeagueState::Complete]);
    SidecarSnapshotFactory::matchStarted($last);
    SidecarSnapshotFactory::snapshot($last, 'started', SidecarSnapshotFactory::league(['match_number' => 5, 'game_history' => array_map(fn ($i) => ['match_id' => 100 + $i], range(1, 4))]));
    ($this->joinedState)($last);

    ApplySidecarProjection::run($last->fresh());

    expect($last->fresh()->league_id)->toBe($league->id)
        ->and(League::count())->toBe(1)
        ->and($league->fresh()->state)->toBe(LeagueState::Complete);
});

it('does not move a match out of a dropped league or reopen it', function () {
    $league = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Dropped]);
    $m = ($this->newMatch)(['league_id' => $league->id, 'state' => MatchState::Complete]);
    SidecarSnapshotFactory::matchStarted($m);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());
    ($this->joinedState)($m);

    ApplySidecarProjection::run($m->fresh());

    expect($m->fresh()->league_id)->toBe($league->id)
        ->and($league->fresh()->state)->toBe(LeagueState::Dropped)
        ->and(League::count())->toBe(1);
});

it('does not reshuffle leagues when an older match of a closed run is re-projected', function () {
    $oldRun = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Partial, 'started_at' => now()->subHour()]);
    $old = ($this->newMatch)(['league_id' => $oldRun->id, 'mtgo_id' => '100', 'started_at' => now()->subHour()]);
    $current = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    ($this->newMatch)(['league_id' => $current->id, 'mtgo_id' => '200']);
    SidecarSnapshotFactory::matchStarted($old);
    SidecarSnapshotFactory::snapshot($old, 'started', SidecarSnapshotFactory::league());
    ($this->joinedState)($old);

    ApplySidecarProjection::run($old->fresh());

    expect($old->fresh()->league_id)->toBe($oldRun->id)
        ->and($oldRun->fresh()->state)->toBe(LeagueState::Partial)
        ->and($current->fresh()->state)->toBe(LeagueState::Active);
});

it('never moves a match out of a manual league', function () {
    $manual = League::factory()->manual()->create(['token' => 'league-token-123']);
    $m = ($this->newMatch)(['league_id' => $manual->id, 'state' => MatchState::Complete]);
    SidecarSnapshotFactory::matchStarted($m);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());
    ($this->joinedState)($m);

    ApplySidecarProjection::run($m->fresh());

    expect($m->fresh()->league_id)->toBe($manual->id)->and(League::count())->toBe(1);
});

it('waits for a deferred deck before minting even when the league snapshot is there', function () {
    SidecarSnapshotFactory::attachedStatus($this->dir);
    SidecarAuthorityFlags::applyRemote(['league_run' => true, 'match_deck' => true]);
    $m = ($this->newMatch)(['deck_version_id' => null]);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    AssignLeague::run($m, $this->meta);

    expect($m->fresh()->league_id)->toBeNull();
});

it('backfills a missing league deck from the match it attaches', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'deck_version_id' => null]);
    ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));

    AssignLeague::run($m, $this->meta);

    expect($run->fresh()->deck_version_id)->toBe($this->dv->id);
});

it('keeps the correction diff after the next idempotent pass', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Partial]);
    ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    $logMinted = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $m = ($this->newMatch)(['league_id' => $logMinted->id]);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]));

    ResolveLeagueRunFromSidecar::run($m, $this->meta);
    ResolveLeagueRunFromSidecar::run($m->fresh(), $this->meta);

    expect(GameFieldDiff::where('field', 'league_run')->exists())->toBeTrue();
});

it('ignores a history-less partial league from an older run', function () {
    $stale = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Partial]);
    ($this->newMatch)(['league_id' => $stale->id, 'mtgo_id' => '100']);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 3, 'game_history' => null]));

    AssignLeague::run($m, $this->meta);

    expect($m->fresh()->league_id)->not->toBe($stale->id)
        ->and($stale->fresh()->state)->toBe(LeagueState::Partial);
});

it('scopes candidates to the snapshot token', function () {
    $otherSeason = League::factory()->create(['event_id' => 10983, 'token' => 'last-season-token']);
    ($this->newMatch)(['league_id' => $otherSeason->id, 'mtgo_id' => '100']);
    $m = ($this->newMatch)();
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league());

    AssignLeague::run($m, $this->meta);

    expect($otherSeason->fresh()->state)->toBe(LeagueState::Active)
        ->and($m->fresh()->league_id)->not->toBe($otherSeason->id);
});

it('corrects only when the snapshot is new, then stops touching the match', function () {
    $run = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123', 'state' => LeagueState::Partial]);
    ($this->newMatch)(['league_id' => $run->id, 'mtgo_id' => '100']);
    $logMinted = League::factory()->create(['event_id' => 10983, 'token' => 'league-token-123']);
    $m = ($this->newMatch)(['league_id' => $logMinted->id]);
    SidecarSnapshotFactory::matchStarted($m);
    SidecarSnapshotFactory::snapshot($m, 'started', SidecarSnapshotFactory::league(['match_number' => 2, 'game_history' => [['match_id' => 100]]]))->update(['processed_at' => now()]);
    ($this->joinedState)($m);

    ApplySidecarProjection::run($m->fresh());

    expect($m->fresh()->league_id)->toBe($logMinted->id);
});
