<?php

use App\Exceptions\OfflineModeException;
use App\Facades\AppSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

it('allows the gated macro while online', function () {
    AppSettings::setOffline(false);

    expect(Http::mymtgoApi())->toBeInstanceOf(PendingRequest::class);
});

it('throws from the gated macro while offline', function () {
    AppSettings::setOffline(true);

    Http::mymtgoApi();
})->throws(OfflineModeException::class);

it('allows the reference macro while offline', function () {
    AppSettings::setOffline(true);

    expect(Http::mymtgoReference())->toBeInstanceOf(PendingRequest::class);
});
