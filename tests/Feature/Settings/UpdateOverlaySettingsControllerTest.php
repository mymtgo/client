<?php

use Native\Desktop\Facades\Settings;

it('persists overlay enabled setting', function () {
    Settings::shouldReceive('set')->with('overlay_enabled', 1)->once();

    $this->post(route('settings.overlay'), [
        'overlay_enabled' => true,
    ])->assertRedirect();
});

it('validates overlay enabled is boolean', function () {
    $this->post(route('settings.overlay'), [
        'overlay_enabled' => 'not-a-bool',
    ])->assertSessionHasErrors(['overlay_enabled']);
});
