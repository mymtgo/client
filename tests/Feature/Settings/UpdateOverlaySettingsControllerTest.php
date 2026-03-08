<?php

use Native\Desktop\Facades\Settings;

it('persists all overlay settings', function () {
    Settings::shouldReceive('set')->with('overlay_enabled', 1)->once();
    Settings::shouldReceive('set')->with('overlay_always_show', 0)->once();
    Settings::shouldReceive('set')->with('overlay_font', 'Consolas')->once();
    Settings::shouldReceive('set')->with('overlay_text_color', '#ff0000')->once();
    Settings::shouldReceive('set')->with('overlay_bg_color', '#000000')->once();

    $this->post(route('settings.overlay'), [
        'overlay_enabled' => true,
        'overlay_always_show' => false,
        'overlay_font' => 'Consolas',
        'overlay_text_color' => '#ff0000',
        'overlay_bg_color' => '#000000',
    ])->assertRedirect();
});

it('validates overlay settings', function () {
    $this->post(route('settings.overlay'), [
        'overlay_enabled' => 'not-a-bool',
        'overlay_font' => 'Comic Sans MS',
        'overlay_text_color' => 'not-a-color',
    ])->assertSessionHasErrors(['overlay_enabled', 'overlay_font', 'overlay_text_color']);
});
