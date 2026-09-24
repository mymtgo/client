<?php

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Facades\AppSettings;
use App\Jobs\DownloadSidecarJob;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Native\Desktop\Facades\ChildProcess;

it('records unavailable and spawns nothing when the exe is missing', function () {
    $fake = ChildProcess::fake();

    StartSidecarSupervisor::run();

    expect(AppSettings::sidecarAvailable())->toBeFalse();
    expect($fake->starts)->toBeEmpty();
});

it('spawns nothing when disabled even if the exe exists', function () {
    $fake = ChildProcess::fake();
    AppSettings::setSidecarEnabled(false);

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe');

    expect(AppSettings::sidecarAvailable())->toBeTrue();
    expect($fake->starts)->toBeEmpty();
});

it('spawns the sidecar with output directory and parent pid', function () {
    ChildProcess::fake();
    AppSettings::setSidecarDirectory('/tmp/sidecar-out');
    AppSettings::setOffline(true);

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe');

    ChildProcess::assertStarted(function ($cmd, $alias, $cwd, $env, $persistent) {
        return $alias === StartSidecarSupervisor::ALIAS
            && $persistent === true
            && $cmd[0] === '/fake/mymtgo-helper.exe'
            && in_array('--out', $cmd, true)
            && in_array('/tmp/sidecar-out', $cmd, true)
            && in_array('--parent-pid', $cmd, true)
            && in_array('--config', $cmd, true)
            && $cmd[array_search('--config', $cmd, true) + 1] === '';
    });
});

it('passes an empty config url when online until the API config endpoint exists', function () {
    // Plan 1 built this from the app's own local http server, which has no
    // such route and which the exe rejects with exit code 2. Plan 3 replaces
    // CONFIG_URL with the real API endpoint and this test with a URL assertion.
    ChildProcess::fake();
    AppSettings::setOffline(false);
    AppSettings::setAppServerUrl('http://127.0.0.1:8100');

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe');

    ChildProcess::assertStarted(fn ($cmd, $alias, $cwd, $env, $persistent) => $cmd[array_search('--config', $cmd, true) + 1] === '');
});

it('trips after five crashes in ten minutes', function () {
    ChildProcess::fake();

    for ($i = 0; $i < 4; $i++) {
        expect(StartSidecarSupervisor::handleExit())->toBeFalse();
    }
    expect(StartSidecarSupervisor::handleExit())->toBeTrue();
    expect(AppSettings::sidecarTripped())->toBeTrue();
    ChildProcess::assertStop(StartSidecarSupervisor::ALIAS);
});

it('clears the trip on boot', function () {
    ChildProcess::fake();
    AppSettings::setSidecarTripped(true);

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe');

    expect(AppSettings::sidecarTripped())->toBeFalse();
    ChildProcess::assertStarted(fn ($cmd, $alias, $cwd, $env, $persistent) => $alias === StartSidecarSupervisor::ALIAS);
});

it('does not count an exit as a crash while the sidecar is switched off', function () {
    ChildProcess::fake();
    AppSettings::setSidecarEnabled(false);

    for ($i = 0; $i < 10; $i++) {
        expect(StartSidecarSupervisor::handleExit())->toBeFalse();
    }

    expect(AppSettings::sidecarTripped())->toBeFalse();
});

it('trips immediately on a usage error exit because respawning cannot fix the arguments', function () {
    ChildProcess::fake();

    expect(StartSidecarSupervisor::handleExit(StartSidecarSupervisor::USAGE_EXIT_CODE))->toBeTrue();
    expect(AppSettings::sidecarTripped())->toBeTrue();
    ChildProcess::assertStop(StartSidecarSupervisor::ALIAS);
});

it('stops a respawn that arrives after the tripwire fired', function () {
    ChildProcess::fake();
    AppSettings::setSidecarTripped(true);

    expect(StartSidecarSupervisor::handleSpawn())->toBeTrue();
    ChildProcess::assertStop(StartSidecarSupervisor::ALIAS);
});

it('leaves a normal spawn alone', function () {
    $fake = ChildProcess::fake();

    expect(StartSidecarSupervisor::handleSpawn())->toBeFalse();
    expect($fake->stops)->toBeEmpty();
});

function useWindowsBin(): string
{
    $bin = sys_get_temp_dir().'/sidecar-bin-'.Str::random(8);
    config([
        'sidecar.platform' => 'Windows',
        'sidecar.bin_directory' => $bin,
        'sidecar.version' => '0.1.0',
        'sidecar.sha256' => str_repeat('a', 64),
    ]);

    return $bin;
}

afterEach(function () {
    if (isset($this->bin)) {
        File::deleteDirectory($this->bin);
    }
});

it('dispatches a download when the pinned exe is missing on Windows', function () {
    Queue::fake();
    ChildProcess::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);

    StartSidecarSupervisor::run();

    Queue::assertPushedOn('sidecar', DownloadSidecarJob::class);
    $state = SidecarDownloadStore::read();
    expect($state->status)->toBe(SidecarDownloadState::DOWNLOADING);
    expect($state->runId)->not->toBeNull();
});

it('does not dispatch on non-Windows', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    config(['sidecar.platform' => 'Darwin']);
    AppSettings::setOffline(false);

    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
});

it('does not dispatch when disabled', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    AppSettings::setSidecarEnabled(false);

    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
});

it('does not dispatch when offline', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(true);

    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
});

it('starts the pinned exe without dispatching when it exists', function () {
    Queue::fake();
    ChildProcess::fake();
    $this->bin = useWindowsBin();
    File::ensureDirectoryExists($this->bin);
    File::put(SidecarPaths::pinnedExe(), 'exe');

    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
    ChildProcess::assertStarted(fn ($cmd, $alias, $cwd, $env, $persistent) => $cmd[0] === SidecarPaths::pinnedExe());
});

it('passes an explicit parent pid through to the helper', function () {
    ChildProcess::fake();

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', parentPid: 4242);

    ChildProcess::assertStarted(fn ($cmd, $alias, $cwd, $env, $persistent) => $cmd[array_search('--parent-pid', $cmd, true) + 1] === '4242');
});

it('sweeps exes for other versions', function () {
    Queue::fake();
    ChildProcess::fake();
    $this->bin = useWindowsBin();
    File::ensureDirectoryExists($this->bin);
    File::put($this->bin.'/mymtgo-helper-0.0.9.exe', 'old');
    File::put(SidecarPaths::pinnedExe(), 'exe');

    StartSidecarSupervisor::run();

    expect(is_file($this->bin.'/mymtgo-helper-0.0.9.exe'))->toBeFalse();
    expect(is_file(SidecarPaths::pinnedExe()))->toBeTrue();
});

it('leaves a fresh download alone', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-live'));

    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
    expect(SidecarDownloadStore::read()->runId)->toBe('run-live');
});

it('re-dispatches a stale download under a new run even while the old lock is held', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    $this->freezeSecond();
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-dead'));
    // Simulate the lock a killed app left behind.
    expect(Cache::lock(UniqueLock::getKey(new DownloadSidecarJob('run-dead')), 1200)->get())->toBeTrue();

    $this->travel(31)->seconds();
    StartSidecarSupervisor::run();

    Queue::assertPushed(DownloadSidecarJob::class, 1);
    expect(SidecarDownloadStore::read()->runId)->not->toBe('run-dead');
});

it('marks a vanished verified exe as quarantined and does not re-download', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    SidecarDownloadStore::write(SidecarDownloadState::ready('0.1.0', 'run-a'));

    StartSidecarSupervisor::run();
    StartSidecarSupervisor::run();

    Queue::assertNothingPushed();
    $state = SidecarDownloadStore::read();
    expect($state->status)->toBe(SidecarDownloadState::FAILED);
    expect($state->error)->toBe(SidecarDownloadState::ERROR_QUARANTINED);
});

it('retries a network or checksum failure on the next boot', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    SidecarDownloadStore::write(SidecarDownloadState::failed('0.1.0', 'run-a', SidecarDownloadState::ERROR_NETWORK));

    StartSidecarSupervisor::run();

    Queue::assertPushed(DownloadSidecarJob::class, 1);
});

it('ignores state left over from another pinned version', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    SidecarDownloadStore::write(SidecarDownloadState::ready('0.0.9', 'run-old'));

    StartSidecarSupervisor::run();

    Queue::assertPushed(DownloadSidecarJob::class, 1);
});

it('dispatches once for two boots in a row', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);

    StartSidecarSupervisor::run();
    StartSidecarSupervisor::run();

    Queue::assertPushed(DownloadSidecarJob::class, 1);
});

it('sweeps orphaned partial and temp files but keeps the live run', function () {
    Queue::fake();
    $this->bin = useWindowsBin();
    AppSettings::setOffline(false);
    SidecarDownloadStore::write(SidecarDownloadState::downloading('0.1.0', 'run-live'));
    File::put(SidecarPaths::pinnedExe().'.partial-run-dead', 'orphan');
    File::put(SidecarPaths::pinnedExe().'.partial-run-live', 'in flight');
    File::put(SidecarPaths::downloadStateFile().'.deadbeef.tmp', 'torn');
    touch(SidecarPaths::downloadStateFile().'.deadbeef.tmp', time() - 120);

    StartSidecarSupervisor::run();

    expect(is_file(SidecarPaths::pinnedExe().'.partial-run-dead'))->toBeFalse();
    expect(is_file(SidecarPaths::downloadStateFile().'.deadbeef.tmp'))->toBeFalse();
    expect(is_file(SidecarPaths::pinnedExe().'.partial-run-live'))->toBeTrue();
});
