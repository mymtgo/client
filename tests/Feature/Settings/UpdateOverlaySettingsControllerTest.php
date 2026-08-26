<?php

use App\Enums\DraftState;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Models\Draft;
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

it('persists the reveals section toggle', function () {
    $this->post(route('settings.overlay'), ['overlay_show_reveals' => false])->assertRedirect();

    expect(AppSettings::overlayShowReveals())->toBeFalse();
});

it('persists the draft notes window setting', function () {
    $this->post(route('settings.overlay'), ['draft_notes_window' => false])->assertRedirect();

    expect(AppSettings::showDraftNotesWindow())->toBeFalse();

    $this->post(route('settings.overlay'), ['draft_notes_window' => true])->assertRedirect();

    expect(AppSettings::showDraftNotesWindow())->toBeTrue();
});

it('validates the draft notes window setting is boolean', function () {
    $this->post(route('settings.overlay'), ['draft_notes_window' => 'nope'])
        ->assertSessionHasErrors(['draft_notes_window']);
});

it('leaves the draft notes window setting alone when the key is absent', function () {
    AppSettings::setShowDraftNotesWindow(false);

    $this->post(route('settings.overlay'), ['game_overlay' => true])->assertRedirect();

    expect(AppSettings::showDraftNotesWindow())->toBeFalse();
});

it('opens the draft notes window when enabled during a live draft', function () {
    AppSettings::setShowDraftNotesWindow(false);
    Draft::factory()->create(['state' => DraftState::Picking]);

    $this->post(route('settings.overlay'), ['draft_notes_window' => true])->assertRedirect();

    Window::assertOpened('draft-notes');
});

it('closes an open draft notes window when disabled mid-draft', function () {
    Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
        new WindowInstance('draft-notes'),
    ]);
    Draft::factory()->create(['state' => DraftState::Picking]);

    $this->post(route('settings.overlay'), ['draft_notes_window' => false])->assertRedirect();

    Window::assertClosed('draft-notes');
});

it('does not open the draft notes window when enabled with no live draft', function () {
    $this->post(route('settings.overlay'), ['draft_notes_window' => true])->assertRedirect();

    Window::assertOpenedCount(0);
});
