<?php

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget(ResolveUpdateStatus::LAST_CHECK_KEY);
});

it('starts a check and marks the status as checking', function () {
    app()->detectEnvironment(fn () => 'production');
    // A production env re-enables CSRF, which this test is not about.
    $this->withoutMiddleware();

    $this->from(route('settings.general'))
        ->post(route('settings.check-updates'))
        ->assertRedirect(route('settings.general'));

    expect(ResolveUpdateStatus::run()['status'])->toBe('checking');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'auto-updater/check-for-updates'));
});

it('does not start a check in a dev build, where electron-updater never reports back', function () {
    $this->from(route('settings.general'))
        ->post(route('settings.check-updates'))
        ->assertRedirect(route('settings.general'));

    expect(ResolveUpdateStatus::run()['status'])->toBe('up_to_date');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'auto-updater/check-for-updates'));
});
