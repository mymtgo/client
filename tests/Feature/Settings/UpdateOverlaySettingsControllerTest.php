<?php

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake());

it('persists league window setting', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => true,
    ])->assertRedirect();

    expect(AppSettings::showLeagueWindow())->toBeTrue();
});

it('validates league window is boolean', function () {
    $this->post(route('settings.overlay'), [
        'league_window' => 'not-a-bool',
    ])->assertSessionHasErrors(['league_window']);
});
