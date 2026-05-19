<?php

use App\Facades\AppSettings;

it('persists autostart enabled via the controller', function () {
    AppSettings::setAutostartEnabled(false);

    $this->patch(route('settings.autostart'), ['enabled' => true])
        ->assertRedirect();

    expect(AppSettings::autostartEnabled())->toBeTrue();
});

it('persists autostart disabled via the controller', function () {
    AppSettings::setAutostartEnabled(true);

    $this->patch(route('settings.autostart'), ['enabled' => false])
        ->assertRedirect();

    expect(AppSettings::autostartEnabled())->toBeFalse();
});

it('rejects requests without the enabled flag', function () {
    $this->patch(route('settings.autostart'), [])
        ->assertSessionHasErrors('enabled');
});
