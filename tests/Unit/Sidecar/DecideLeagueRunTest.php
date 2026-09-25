<?php

use App\Actions\Leagues\DecideLeagueRun;
use App\Enums\LeagueState;
use App\Sidecar\LeagueCandidate;
use App\Sidecar\LeagueSnapshot;
use Tests\Helpers\SidecarSnapshotFactory;

function runSnapshot(int $matchNumber, ?array $history = []): LeagueSnapshot
{
    return LeagueSnapshot::fromArray(SidecarSnapshotFactory::league([
        'match_number' => $matchNumber,
        'game_history' => $history === null ? null : array_map(fn ($id) => ['match_id' => $id], $history),
    ]));
}

it('mints for a new run with no candidate', function () {
    $d = DecideLeagueRun::run(runSnapshot(1), [], 5);

    expect($d->mint)->toBeTrue()->and($d->targetLeagueId)->toBeNull()->and($d->close)->toBe([]);
});

it('re-entry closes the old run and mints', function () {
    $old = new LeagueCandidate(7, LeagueState::Active, ['100', '101']);

    $d = DecideLeagueRun::run(runSnapshot(1), [$old], 5);

    expect($d->mint)->toBeTrue()->and($d->close)->toBe([7 => LeagueState::Partial]);
});

it('re-entry closes a full old run as complete', function () {
    $old = new LeagueCandidate(7, LeagueState::Active, ['1', '2', '3', '4', '5']);

    expect(DecideLeagueRun::run(runSnapshot(1), [$old], 5)->close)->toBe([7 => LeagueState::Complete]);
});

it('new run reuses an empty candidate instead of minting', function () {
    $empty = new LeagueCandidate(9, LeagueState::Active, []);

    $d = DecideLeagueRun::run(runSnapshot(1), [$empty], 5);

    expect($d->mint)->toBeFalse()->and($d->targetLeagueId)->toBe(9);
});

it('mid-run attaches to the league whose matches are all in the history', function () {
    $run = new LeagueCandidate(7, LeagueState::Active, ['100']);

    $d = DecideLeagueRun::run(runSnapshot(2, [100]), [$run], 5);

    expect($d->targetLeagueId)->toBe(7)->and($d->mint)->toBeFalse()->and($d->close)->toBe([]);
});

it('unwatched re-entry: the old run is not in the history, so it is closed', function () {
    $oldRun = new LeagueCandidate(7, LeagueState::Active, ['100']);

    $d = DecideLeagueRun::run(runSnapshot(3, [200, 201]), [$oldRun], 5);

    expect($d->mint)->toBeTrue()->and($d->close)->toBe([7 => LeagueState::Partial]);
});

it('falls back to counters when the history is unreadable', function () {
    $run = new LeagueCandidate(7, LeagueState::Active, ['100']);

    expect(DecideLeagueRun::run(runSnapshot(2, null), [$run], 5)->targetLeagueId)->toBe(7);

    $tooMany = new LeagueCandidate(7, LeagueState::Active, ['100', '101', '102']);
    $d = DecideLeagueRun::run(runSnapshot(2, null), [$tooMany], 5);

    expect($d->mint)->toBeTrue()->and($d->close)->toBe([7 => LeagueState::Partial]);
});

it('reactivates a partial league the history confirms', function () {
    $wronglyClosed = new LeagueCandidate(7, LeagueState::Partial, ['100']);
    $logMinted = new LeagueCandidate(8, LeagueState::Active, []);

    $d = DecideLeagueRun::run(runSnapshot(2, [100]), [$logMinted, $wronglyClosed], 5);

    expect($d->targetLeagueId)->toBe(7)->and($d->reactivate)->toBeTrue()->and($d->close)->toBe([]);
});

it('prefers an active candidate over a partial one when both belong', function () {
    $partial = new LeagueCandidate(7, LeagueState::Partial, ['100']);
    $active = new LeagueCandidate(8, LeagueState::Active, ['100']);

    expect(DecideLeagueRun::run(runSnapshot(2, [100]), [$partial, $active], 5)->targetLeagueId)->toBe(8);
});

it('prefers a candidate with matches over an empty one mid-run', function () {
    $empty = new LeagueCandidate(8, LeagueState::Active, []);
    $run = new LeagueCandidate(7, LeagueState::Active, ['100']);

    expect(DecideLeagueRun::run(runSnapshot(2, [100]), [$empty, $run], 5)->targetLeagueId)->toBe(7);
});

it('uses the snapshot total over the default when closing', function () {
    $old = new LeagueCandidate(7, LeagueState::Active, ['1', '2', '3']);
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league(['total_matches' => 3]));

    expect(DecideLeagueRun::run($s, [$old], 5)->close)->toBe([7 => LeagueState::Complete]);
});

it('is undecided without a match number', function () {
    $s = LeagueSnapshot::fromArray(SidecarSnapshotFactory::league(['match_number' => null]));

    expect(DecideLeagueRun::run($s, [], 5)->isUndecided())->toBeTrue();
});

it('never picks a partial league on the counters fallback alone', function () {
    $partial = new LeagueCandidate(7, LeagueState::Partial, ['100']);

    $d = DecideLeagueRun::run(runSnapshot(2, null), [$partial], 5);

    expect($d->mint)->toBeTrue()->and($d->reactivate)->toBeFalse();
});
