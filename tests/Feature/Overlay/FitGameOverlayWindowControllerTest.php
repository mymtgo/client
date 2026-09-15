<?php

use App\Facades\AppSettings;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

it('fits the overlay window to the height the page measured', function () {
    AppSettings::setOverlayShowOpponent(true);
    AppSettings::setOverlayShowDrawOdds(false);
    AppSettings::setOverlayShowReveals(false);
    AppSettings::setOverlayShowSideboard(false);

    $open = (new WindowInstance('game-overlay'))->fromRuntimeWindow((object) ['width' => 320, 'height' => 640]);
    Window::shouldReceive('all')->andReturn([$open]);
    Window::shouldReceive('resize')->once()->with(320, 142, 'game-overlay');

    // The page posts this outside the Inertia router (a plain fetch), so the
    // response must not be a redirect an Inertia visit would follow.
    $this->postJson(route('overlay.fit'), ['fixed_height' => 142])
        ->assertNoContent();
});

it('rejects a missing or absurd height', function () {
    Window::shouldReceive('resize')->never();

    $this->postJson(route('overlay.fit'), [])->assertUnprocessable();
    $this->postJson(route('overlay.fit'), ['fixed_height' => 5000])->assertUnprocessable();
});
