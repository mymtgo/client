<?php

use App\Facades\AppSettings;
use App\Sidecar\SidecarAuthorityFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
use Illuminate\Support\Str;

beforeEach(fn () => AppSettings::setDebugMode(true));

it('lets a player hand a field back to the logs', function () {
    expect(SidecarAuthorityFlags::isOn('league_run'))->toBeTrue();

    $this->patch(route('debug.sidecar.authority'), ['field' => 'league_run', 'enabled' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SidecarAuthorityFlags::isOn('league_run'))->toBeFalse()
        ->and(AppSettings::sidecarAuthority())->toBe(['league_run' => false]);
});

it('drops the override when a field is set back to its default', function () {
    AppSettings::setSidecarAuthority(['league_run' => false, 'match_deck' => false]);

    $this->patch(route('debug.sidecar.authority'), ['field' => 'league_run', 'enabled' => true]);

    // No stored value left for league_run, so a later change to its default still applies.
    expect(AppSettings::sidecarAuthority())->toBe(['match_deck' => false])
        ->and(SidecarAuthorityFlags::isOn('league_run'))->toBeTrue();
});

it('rejects unknown fields', function () {
    $this->patch(route('debug.sidecar.authority'), ['field' => 'everything', 'enabled' => false])
        ->assertSessionHasErrors('field');

    expect(AppSettings::sidecarAuthority())->toBe([]);
});

it('is only reachable in debug mode', function () {
    AppSettings::setDebugMode(false);

    $this->patch(route('debug.sidecar.authority'), ['field' => 'league_run', 'enabled' => false])
        ->assertRedirect('/');

    expect(AppSettings::sidecarAuthority())->toBe([]);
});

it('shows which fields are overridden on the debug page', function () {
    config(['sidecar.bin_directory' => sys_get_temp_dir().'/sidecar-bin-'.Str::random(8)]);
    AppSettings::setSidecarAuthority(['league_run' => false]);

    $this->get(route('debug.sidecar.index'))
        ->assertInertia(fn ($page) => $page
            ->where('settings.authority.league_run', false)
            ->where('settings.authorityDefaults.league_run', true));
});
