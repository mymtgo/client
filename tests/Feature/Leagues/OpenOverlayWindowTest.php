<?php

use App\Actions\Leagues\OpenOverlayWindow;
use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

/**
 * The card's glow is a box-shadow outside the card, so the window is
 * transparent and padded around it; an opaque, card-sized window clips it.
 */
function openOverlayWindowOptions(): array
{
    // The fake lists only main (so the overlay is "not open") and hands that
    // same instance back from open(), which is what the builder calls chain on.
    $window = new WindowInstance('main');
    Window::fake()->alwaysReturnWindows([$window]);

    OpenOverlayWindow::run();

    return $window->toArray();
}

it('opens a transparent, shadowless window padded around the full card', function () {
    $options = openOverlayWindowOptions();

    expect($options['transparent'])->toBeTrue()
        ->and($options['hasShadow'])->toBeFalse()
        ->and($options['windowButtonVisibility'])->toBeFalse()
        ->and($options['width'])->toBe(300 + 2 * OpenOverlayWindow::PADDING)
        ->and($options['height'])->toBe(100 + 2 * OpenOverlayWindow::PADDING);
});

it('pads the compact size too', function () {
    AppSettings::setOverlaySize('compact');

    $options = openOverlayWindowOptions();

    expect($options['width'])->toBe(240 + 2 * OpenOverlayWindow::PADDING)
        ->and($options['height'])->toBe(58 + 2 * OpenOverlayWindow::PADDING);
});
