<?php

use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\GameEvent;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The session id baked into tests/fixtures/sidecar/events-fixture.ndjson
    // and its default `current_file`, so a probe's `session` column can be
    // set to match (or deliberately mismatch) the live status file.
    $this->session = 'aaaaaaaa-0000-0000-0000-000000000001';

    $this->dir = sys_get_temp_dir().'/sidecar-username-'.uniqid();
    mkdir($this->dir);

    $this->writeStatus = function (array $overrides = []) {
        $status = json_decode(file_get_contents(base_path('tests/fixtures/sidecar/status.json')), true);
        $status = array_merge($status, [
            'heartbeat' => now()->toIso8601ZuluString(),
            'current_file' => "events-{$this->session}.ndjson",
        ], $overrides);
        file_put_contents($this->dir.'/status.json', json_encode($status));
    };
    ($this->writeStatus)();

    AppSettings::setSidecarDirectory($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('prefers the latest verified probe username when the flag is on', function () {
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => 'probe_user'], 'seq' => 1, 'session_started_at' => now()->subHour()]);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => 'newer_probe_user'], 'seq' => 2, 'session_started_at' => now()->subHour()]);

    expect(Mtgo::getUsername())->toBe('newer_probe_user');
});

it('ignores unverified probes', function () {
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => false, 'session' => $this->session, 'data' => ['passed' => false, 'username' => 'bad'], 'seq' => 1]);

    expect(Mtgo::getUsername())->toBe('log_user');
});

it('falls back to the active account when the flag is off', function () {
    SidecarAuthorityFlags::applyRemote(['username' => false]);
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => 'probe_user'], 'seq' => 1]);

    expect(Mtgo::getUsername())->toBe('log_user');
});

it('prefers a live probe over the in-memory username', function () {
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => 'probe_user'], 'seq' => 1]);

    // setUsername() itself calls getUsername() once it has set the
    // in-memory value, so the probe must already exist before this call:
    // the resolved value is memoized for the rest of the test.
    Mtgo::setUsername('memory_user');

    expect(Mtgo::getUsername())->toBe('probe_user');
});

it('falls through when the probe username is an empty string', function () {
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => ''], 'seq' => 1]);

    expect(Mtgo::getUsername())->toBe('log_user');
});

it('ignores a probe from a session other than the live status file', function () {
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => 'zzzzzzzz-0000-0000-0000-000000000002', 'data' => ['passed' => true, 'username' => 'probe_user'], 'seq' => 1]);

    expect(Mtgo::getUsername())->toBe('log_user');
});

it('ignores a probe when the sidecar heartbeat is stale', function () {
    ($this->writeStatus)(['heartbeat' => now()->subMinutes(5)->toIso8601ZuluString()]);
    Account::registerAndActivate('log_user', 1);
    GameEvent::factory()->create(['type' => 'probe', 'verified' => true, 'session' => $this->session, 'data' => ['passed' => true, 'username' => 'probe_user'], 'seq' => 1]);

    expect(Mtgo::getUsername())->toBe('log_user');
});
