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

    $this->from(route('overlay.game'))
        ->post(route('overlay.fit'), ['fixed_height' => 142])
        ->assertRedirect(route('overlay.game'));
});

it('rejects a missing or absurd height', function () {
    Window::shouldReceive('resize')->never();

    $this->post(route('overlay.fit'), [])->assertSessionHasErrors('fixed_height');
    $this->post(route('overlay.fit'), ['fixed_height' => 5000])->assertSessionHasErrors('fixed_height');
});
