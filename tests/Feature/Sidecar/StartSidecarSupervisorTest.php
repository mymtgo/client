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

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: true);

    expect(AppSettings::sidecarAvailable())->toBeTrue();
    expect($fake->starts)->toBeEmpty();
});

it('spawns the sidecar with output directory and parent pid', function () {
    ChildProcess::fake();
    AppSettings::setSidecarDirectory('/tmp/sidecar-out');
    AppSettings::setOffline(true);

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: true);

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

it('passes the app server config url when online', function () {
    ChildProcess::fake();
    AppSettings::setOffline(false);
    AppSettings::setAppServerUrl('https://api.example.test');

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: true);

    ChildProcess::assertStarted(fn ($cmd, $alias, $cwd, $env, $persistent) => $cmd[array_search('--config', $cmd, true) + 1] === 'https://api.example.test/sidecar/config');
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

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: true);

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

it('records unavailable and runtime missing, and spawns nothing, when the .NET runtime is absent', function () {
    $fake = ChildProcess::fake();

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: false);

    expect(AppSettings::sidecarAvailable())->toBeFalse();
    expect(AppSettings::sidecarRuntimeMissing())->toBeTrue();
    expect($fake->starts)->toBeEmpty();
});

it('clears runtime missing once the runtime is present', function () {
    ChildProcess::fake();
    AppSettings::setSidecarRuntimeMissing(true);

    StartSidecarSupervisor::run(exePath: '/fake/mymtgo-helper.exe', runtimeInstalled: true);

    expect(AppSettings::sidecarRuntimeMissing())->toBeFalse();
    expect(AppSettings::sidecarAvailable())->toBeTrue();
});

it('does not report runtime missing when there is no exe to run', function () {
    ChildProcess::fake();

    StartSidecarSupervisor::run(runtimeInstalled: false);

    expect(AppSettings::sidecarRuntimeMissing())->toBeFalse();
    expect(AppSettings::sidecarAvailable())->toBeFalse();
});

it('treats the apphost missing-runtime exit as unavailable, not as a crash', function () {
    ChildProcess::fake();

    expect(StartSidecarSupervisor::handleExit(-2147450730))->toBeFalse();

    expect(AppSettings::sidecarRuntimeMissing())->toBeTrue();
    expect(AppSettings::sidecarAvailable())->toBeFalse();
    expect(AppSettings::sidecarTripped())->toBeFalse();
    ChildProcess::assertStop(StartSidecarSupervisor::ALIAS);

    for ($i = 0; $i < 10; $i++) {
        StartSidecarSupervisor::handleExit(-2147450730);
    }
    expect(AppSettings::sidecarTripped())->toBeFalse();
});
