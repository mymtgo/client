<?php

use App\Actions\Outbox\PushMatch;
use App\Data\OAuthTokens;
use App\Facades\AppSettings;
use App\Models\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The global Pest beforeEach registers a catch-all Http::fake() (NativePHP
// guard) whose stub wins over later, more specific fakes. Swap in a fresh
// factory so this file's non-200 stubs actually match.
beforeEach(fn () => Http::swap(new Factory));

function pendingOutboxRow(int $fileVersion = 1): Outbox
{
    return Outbox::create([
        'match_key' => 'tok-1',
        'payload' => ['match_key' => 'tok-1', 'file_version' => $fileVersion, 'match' => ['token' => 'tok-1']],
        'file_version' => $fileVersion,
        'status' => 'pending',
    ]);
}

function bindPushAccount(): void
{
    AppSettings::setOauthTokens(new OAuthTokens(
        accessToken: 'tok-secret-123',
        refreshToken: 'ref-1',
        expiresAt: now()->addHour()->toIso8601String(),
    ));
}

it('marks synced on a 200 from the sink', function () {
    bindPushAccount();
    Http::fake([config('mymtgo_api.url').'/api/matches' => Http::response(['accepted' => true], 200)]);
    $row = pendingOutboxRow(fileVersion: 3);

    app(PushMatch::class)->run($row);

    $fresh = $row->fresh();
    expect($fresh->status)->toBe('synced');
    expect($fresh->synced_version)->toBe(3);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok-secret-123')
        && str_ends_with($request->url(), '/api/matches')
        && $request['match_key'] === 'tok-1');
});

it('records the error and stays pending on a 500', function () {
    bindPushAccount();
    Http::fake([config('mymtgo_api.url').'/api/matches' => Http::response('boom', 500)]);
    $row = pendingOutboxRow();

    app(PushMatch::class)->run($row);

    $fresh = $row->fresh();
    expect($fresh->status)->toBe('pending');
    expect($fresh->attempts)->toBe(1);
    expect($fresh->last_error)->not->toBeNull();
});

it('holds without sending when no account token is available', function () {
    Http::fake();
    $row = pendingOutboxRow();

    app(PushMatch::class)->run($row);

    expect($row->fresh()->status)->toBe('pending');
    expect($row->fresh()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('does not stomp a newer version that raced in during the push', function () {
    bindPushAccount();
    Http::fake([config('mymtgo_api.url').'/api/matches' => Http::response(['accepted' => true], 200)]);
    $row = pendingOutboxRow(fileVersion: 1);

    // Simulate a recompile bumping the row between read and ack.
    $stale = $row->replicate();
    $row->update(['file_version' => 2, 'payload' => ['match_key' => 'tok-1', 'file_version' => 2]]);
    $stale->id = $row->id;
    $stale->exists = true;

    app(PushMatch::class)->run($stale);

    $fresh = $row->fresh();
    expect($fresh->status)->toBe('pending');      // v2 still needs pushing
    expect($fresh->synced_version)->toBe(1);      // v1 ack recorded
});

it('marks failed after exhausting attempts', function () {
    bindPushAccount();
    Http::fake([config('mymtgo_api.url').'/api/matches' => Http::response('boom', 500)]);
    $row = pendingOutboxRow();
    $row->update(['attempts' => PushMatch::MAX_ATTEMPTS - 1]);

    app(PushMatch::class)->run($row->fresh());

    expect($row->fresh()->status)->toBe('failed');
});
