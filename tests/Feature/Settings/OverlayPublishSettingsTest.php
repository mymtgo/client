<?php

use App\Facades\AppSettings;

it('defaults publishing off and persists the toggle', function () {
    expect(AppSettings::overlayPublish())->toBeFalse();

    AppSettings::setOverlayPublish(true);

    expect(AppSettings::overlayPublish())->toBeTrue();
});

it('stores deck labels trimmed, capped at 40 and removed when blank', function () {
    AppSettings::setOverlayDeckLabel(7, '  Tron, again  ');
    AppSettings::setOverlayDeckLabel(9, str_repeat('x', 60));

    expect(AppSettings::overlayDeckLabels())->toBe([7 => 'Tron, again', 9 => str_repeat('x', 40)]);

    AppSettings::setOverlayDeckLabel(7, '   ');
    AppSettings::setOverlayDeckLabel(9, null);

    expect(AppSettings::overlayDeckLabels())->toBe([]);
});

it('round-trips the remote background and last published time', function () {
    expect(AppSettings::overlayBackgroundRemote())->toBeNull()
        ->and(AppSettings::overlayLastPublishedAt())->toBeNull();

    AppSettings::setOverlayBackgroundRemote(['url' => 'https://cdn.example/bg.png', 'hash' => 'abc']);
    AppSettings::setOverlayLastPublishedAt('2026-10-01T12:00:00+00:00');

    expect(AppSettings::overlayBackgroundRemote())->toBe(['url' => 'https://cdn.example/bg.png', 'hash' => 'abc'])
        ->and(AppSettings::overlayLastPublishedAt())->toBe('2026-10-01T12:00:00+00:00');

    AppSettings::setOverlayBackgroundRemote(null);

    expect(AppSettings::overlayBackgroundRemote())->toBeNull();
});

it('round-trips the publish failure reason', function () {
    expect(AppSettings::overlayPublishError())->toBeNull();

    AppSettings::setOverlayPublishError('not_claimed');

    expect(AppSettings::overlayPublishError())->toBe('not_claimed');
});
