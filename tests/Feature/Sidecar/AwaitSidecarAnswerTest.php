<?php

use App\Actions\Sidecar\AwaitSidecarAnswer;
use App\Facades\AppSettings;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sidecar-await-'.uniqid();
    mkdir($this->dir);
    AppSettings::setSidecarDirectory($this->dir);
    $this->writeStatus = function (string $state = 'attached', ?string $heartbeat = null): void {
        file_put_contents($this->dir.'/status.json', json_encode([
            'state' => $state,
            'heartbeat' => $heartbeat ?? now()->toIso8601ZuluString(),
        ]));
    };
    SidecarAuthorityFlags::applyRemote(['league_run' => true]);
});

it('waits while the flag is on, the sidecar is healthy and the anchor is fresh', function () {
    ($this->writeStatus)();

    expect(AwaitSidecarAnswer::run('league_run', now()->subSeconds(2), now()->subSeconds(10)))->toBeTrue();
});

it('stops waiting once the window has passed', function () {
    ($this->writeStatus)();

    expect(AwaitSidecarAnswer::run('league_run', now()->subSeconds(6)))->toBeFalse();
});

it('never waits for a match that is not live', function () {
    ($this->writeStatus)();

    expect(AwaitSidecarAnswer::run('league_run', now(), now()->subMinutes(10)))->toBeFalse();
});

it('never waits when the flag is off', function () {
    ($this->writeStatus)();

    expect(AwaitSidecarAnswer::run('match_deck', now()))->toBeFalse();
});

it('never waits when the sidecar is missing, detached or stale', function (?string $state, ?string $heartbeat) {
    if ($state !== null) {
        ($this->writeStatus)($state, $heartbeat);
    }

    expect(AwaitSidecarAnswer::run('league_run', now()))->toBeFalse();
})->with([
    'no status file' => [null, null],
    'waiting' => ['waiting', null],
    'stale heartbeat' => ['attached', '2020-01-01T00:00:00Z'],
]);

it('never waits without an anchor', function () {
    ($this->writeStatus)();

    expect(AwaitSidecarAnswer::run('league_run', null))->toBeFalse();
});

it('runs no database queries', function () {
    ($this->writeStatus)();
    DB::enableQueryLog();

    AwaitSidecarAnswer::run('league_run', now(), now());

    expect(DB::getQueryLog())->toBe([]);
});
