<?php

use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Http;

// Same stub reset as SyncApiTest: the suite's blanket Http::fake() would win otherwise.
beforeEach(function () {
    $reflection = new ReflectionProperty(Http::getFacadeRoot(), 'stubCallbacks');
    $reflection->setAccessible(true);
    $reflection->setValue(Http::getFacadeRoot(), collect());

    app(SyncTokens::class)->store('access-token', 'refresh-token', 2592000);
});

it('puts the overlay state with the login id', function () {
    Http::fake(['*/api/overlay' => Http::response(null, 204)]);

    app(SyncApi::class)->publishOverlay(4242, ['status' => 'idle', 'art' => null]);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://mymtgo.com/api/overlay'
        && $request->hasHeader('Authorization', 'Bearer access-token')
        && $request['login_id'] === 4242
        && $request['state'] === ['status' => 'idle', 'art' => null]);
});

it('throws when the publish fails', function () {
    Http::fake(['*/api/overlay' => Http::response(['error' => 'player_not_claimed'], 409)]);

    app(SyncApi::class)->publishOverlay(4242, ['status' => 'idle']);
})->throws(RuntimeException::class);

it('clears the overlay and treats a 404 as done', function () {
    Http::fake(['*/api/overlay/4242' => Http::response(null, 404)]);

    app(SyncApi::class)->clearOverlay(4242);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://mymtgo.com/api/overlay/4242');
});

it('uploads the background and returns its url', function () {
    Http::fake(['*/api/overlay/background' => Http::response(['url' => 'https://cdn.example/bg.png'], 201)]);

    $url = app(SyncApi::class)->uploadOverlayBackground('png-bytes', 'background.png');

    expect($url)->toBe('https://cdn.example/bg.png');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->isMultipart());
});

it('deletes the remote background', function () {
    Http::fake(['*/api/overlay/background' => Http::response(null, 204)]);

    app(SyncApi::class)->deleteOverlayBackground();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'https://mymtgo.com/api/overlay/background');
});
