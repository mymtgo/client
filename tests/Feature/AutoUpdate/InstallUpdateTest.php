<?php

use App\Actions\AutoUpdate\InstallDownloadedUpdate;
use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('clears the downloaded update and asks electron to install', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    InstallDownloadedUpdate::run();

    expect(Cache::get(ResolveUpdateStatus::DOWNLOADED_KEY))->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'auto-updater/quit-and-install'));
});

it('installs through the install route', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');

    $this->get(route('updates.install'))->assertOk();

    expect(Cache::get(ResolveUpdateStatus::DOWNLOADED_KEY))->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'auto-updater/quit-and-install'));
});

it('still clears the download when electron is unreachable', function () {
    ResolveUpdateStatus::recordDownloaded('0.45.0');
    Http::fake(fn () => throw new ConnectionException('down'));

    // Throwing here would error the test: the action must swallow and report.
    InstallDownloadedUpdate::run();

    expect(Cache::get(ResolveUpdateStatus::DOWNLOADED_KEY))->toBeNull();
});
