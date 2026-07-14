<?php

use App\Actions\Device\ResolveDeviceId;
use App\Facades\AppSettings;
use Illuminate\Support\Str;

it('mints a ulid on first resolve and persists it', function () {
    $id = app(ResolveDeviceId::class)->run();

    expect(Str::isUlid($id))->toBeTrue();
    expect(AppSettings::deviceId())->toBe($id);
});

it('returns the cached id on subsequent resolves', function () {
    $ulid = Str::ulid()->toBase32();
    AppSettings::setDeviceId($ulid);

    expect(app(ResolveDeviceId::class)->run())->toBe($ulid);
});

it('is stable across calls', function () {
    $first = app(ResolveDeviceId::class)->run();
    $second = app(ResolveDeviceId::class)->run();

    expect($second)->toBe($first);
});

it('re-mints when the cached id predates the ulid contract', function () {
    AppSettings::setDeviceId(hash('sha256', 'legacy-derived-id'));

    $id = app(ResolveDeviceId::class)->run();

    expect(Str::isUlid($id))->toBeTrue();
    expect(AppSettings::deviceId())->toBe($id);
});
