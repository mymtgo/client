<?php

use Native\Desktop\Facades\Settings;

it('persists league window setting', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => true,
    ])->assertRedirect();

    expect(Settings::get('league_window'))->toBe(1);
});

it('validates league window is boolean', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => 'not-a-bool',
    ])->assertSessionHasErrors(['league_window']);
});
