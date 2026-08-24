<?php

use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake());

function inProgressOverlayMatch(): MtgoMatch
{
    return MtgoMatch::create([
        'mtgo_id' => '777001',
        'token' => 'settings-overlay-token',
        'format' => 'Pmodern',
        'match_type' => 'Constructed',
        'started_at' => now(),
        'state' => MatchState::InProgress,
    ]);
}

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

it('opens the game overlay window when enabled', function () {
    $this->post(route('settings.overlay'), ['game_overlay' => true])->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeTrue();
});

it('closes the game overlay window when disabled', function () {
    AppSettings::setShowGameOverlay(true);

    $this->post(route('settings.overlay'), ['game_overlay' => false])->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeFalse();
});

it('persists section toggles without touching the master', function () {
    AppSettings::setShowGameOverlay(true);

    $this->post(route('settings.overlay'), ['overlay_show_sideboard' => false])->assertRedirect();

    expect(AppSettings::overlayShowSideboard())->toBeFalse();
    expect(AppSettings::showGameOverlay())->toBeTrue();
});

it('does not open the game overlay when enabled with no match in progress', function () {
    $this->post(route('settings.overlay'), ['game_overlay' => true])
        ->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeTrue();
    Window::assertOpenedCount(0);
});

it('opens the game overlay when enabled during an in-progress match', function () {
    inProgressOverlayMatch();

    $this->post(route('settings.overlay'), ['game_overlay' => true])
        ->assertRedirect();

    Window::assertOpened('game-overlay');
});

it('closes an open game overlay when disabled mid-match', function () {
    Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
        new WindowInstance('game-overlay'),
    ]);
    inProgressOverlayMatch();
    AppSettings::setShowGameOverlay(true);

    $this->post(route('settings.overlay'), ['game_overlay' => false])
        ->assertRedirect();

    expect(AppSettings::showGameOverlay())->toBeFalse();
    Window::assertClosed('game-overlay');
});
