<?php

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Facades\AppSettings;
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
