<?php

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

function openOverlayWindow(int $height): void
{
    $open = (new WindowInstance('game-overlay'))->fromRuntimeWindow((object) ['width' => 320, 'height' => $height]);
    Window::shouldReceive('all')->andReturn([$open]);
}

it('is expanded by default', function () {
    expect(AppSettings::overlayCollapsed())->toBeFalse()
        ->and(AppSettings::overlayExpandedHeight())->toBeNull();
});

it('collapses the overlay down to its header and tab bar', function () {
    openOverlayWindow(700);
    Window::shouldReceive('resize')->once()->with(320, 162, 'game-overlay');

    $this->postJson(route('overlay.collapse'), ['collapsed' => true, 'fixed_height' => 120, 'bar_height' => 42])
        ->assertNoContent();

    expect(AppSettings::overlayCollapsed())->toBeTrue()
        ->and(AppSettings::overlayExpandedHeight())->toBe(700);
});

it('restores the height the overlay had before it was collapsed', function () {
    AppSettings::setOverlayCollapsed(true);
    AppSettings::setOverlayExpandedHeight(700);
    openOverlayWindow(162);
    Window::shouldReceive('resize')->once()->with(320, 700, 'game-overlay');

    $this->postJson(route('overlay.collapse'), ['collapsed' => false, 'fixed_height' => 120, 'bar_height' => 42])
        ->assertNoContent();

    expect(AppSettings::overlayCollapsed())->toBeFalse()
        ->and(AppSettings::overlayExpandedHeight())->toBeNull();
});

it('falls back to the default height when expanding with nothing remembered', function () {
    AppSettings::setOverlayShowDrawOdds(true);
    AppSettings::setOverlayCollapsed(true);
    openOverlayWindow(162);
    Window::shouldReceive('resize')->once()->with(320, 120 + 520, 'game-overlay');

    $this->postJson(route('overlay.collapse'), ['collapsed' => false, 'fixed_height' => 120, 'bar_height' => 42])
        ->assertNoContent();
});

it('keeps the remembered height when collapse is sent twice', function () {
    openOverlayWindow(700);
    Window::shouldReceive('resize')->twice();

    $this->postJson(route('overlay.collapse'), ['collapsed' => true, 'fixed_height' => 120, 'bar_height' => 42]);

    // The window is now the collapsed strip; a repeat must not overwrite 700 with it.
    openOverlayWindow(162);
    $this->postJson(route('overlay.collapse'), ['collapsed' => true, 'fixed_height' => 120, 'bar_height' => 42]);

    expect(AppSettings::overlayExpandedHeight())->toBe(700);
});

it('still records the choice when the overlay window is not open', function () {
    Window::shouldReceive('all')->andReturn([]);
    Window::shouldReceive('resize')->never();

    $this->postJson(route('overlay.collapse'), ['collapsed' => true, 'fixed_height' => 120, 'bar_height' => 42])
        ->assertNoContent();

    expect(AppSettings::overlayCollapsed())->toBeTrue();
});

it('rejects missing or absurd values', function () {
    Window::shouldReceive('resize')->never();

    $this->postJson(route('overlay.collapse'), [])->assertUnprocessable();
    $this->postJson(route('overlay.collapse'), ['collapsed' => true, 'fixed_height' => 120, 'bar_height' => 5000])->assertUnprocessable();
});
