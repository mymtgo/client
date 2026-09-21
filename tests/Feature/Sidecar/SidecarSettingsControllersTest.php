<?php

use App\Facades\AppSettings;
use Native\Desktop\Facades\ChildProcess;

it('toggles the sidecar setting', function () {
    $this->patch(route('settings.sidecar-enabled'), ['enabled' => false])->assertRedirect();
    expect(AppSettings::sidecarEnabled())->toBeFalse();

    $this->patch(route('settings.sidecar-enabled'), ['enabled' => true])->assertRedirect();
    expect(AppSettings::sidecarEnabled())->toBeTrue();
});

it('validates the toggle payload', function () {
    $this->patch(route('settings.sidecar-enabled'), ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
});

it('marks the consent notice seen', function () {
    expect(AppSettings::sidecarNoticeSeen())->toBeFalse();
    $this->post(route('settings.sidecar-notice.seen'))->assertRedirect();
    expect(AppSettings::sidecarNoticeSeen())->toBeTrue();
});

it('clears the crash history when the sidecar is switched off', function () {
    ChildProcess::fake();
    AppSettings::recordSidecarCrash();
    AppSettings::recordSidecarCrash();

    $this->patch(route('settings.sidecar-enabled'), ['enabled' => false])->assertRedirect();

    // A fresh count of 1 proves the earlier two were cleared, so an off/on
    // cycle cannot walk the tripwire towards its limit.
    expect(AppSettings::recordSidecarCrash())->toBe(1);
});
