<?php

use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Enums\DraftState;
use App\Facades\AppSettings;
use App\Models\Draft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

uses(RefreshDatabase::class);

function fakeDraftNotesWindowOpen(): void
{
    Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
        new WindowInstance('draft-notes'),
    ]);
}

/**
 * Swap the window facade for a partial mock whose all() throws, standing in
 * for an Electron backend that is not answering on localhost:4000.
 */
function breakWindowApi(): void
{
    $mock = Mockery::mock(Window::getFacadeRoot())->makePartial();
    $mock->shouldReceive('all')->andThrow(new ConnectionException('electron is down'));
    Window::swap($mock);
}

it('opens the window while a draft is picking and the setting is on', function () {
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpened('draft-notes');
});

it('opens the window while a draft is still connecting', function () {
    Draft::factory()->create(['state' => DraftState::Connecting]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpened('draft-notes');
});

it('does not open the window when the setting is off', function () {
    AppSettings::setShowDraftNotesWindow(false);
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(0);
});

it('does not open the window when no draft is live', function () {
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(0);
});

it('does not reopen a window that is already open', function () {
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(0);
});

it('keeps the window open for thirty seconds after the draft finishes', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(29)]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertClosedCount(0);
    Window::assertOpenedCount(0);
});

it('closes the window once the grace period after finishing has passed', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(31)]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertClosed('draft-notes');
});

it('closes the window when the draft is abandoned', function () {
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertClosed('draft-notes');
});

it('closes an open window when the setting is turned off mid-draft', function () {
    AppSettings::setShowDraftNotesWindow(false);
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertClosed('draft-notes');
});

it('does not issue a close when nothing is open', function () {
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertClosedCount(0);
});

it('opens against the stored app server url when set', function () {
    $window = new WindowInstance('main');
    Window::fake()->alwaysReturnWindows([$window]);
    AppSettings::setAppServerUrl('http://127.0.0.1:54321');
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpened('draft-notes');
    expect($window->toArray()['url'])->toBe('http://127.0.0.1:54321/draft-notes');
});

it('falls back to the route url when no server url is stored', function () {
    $window = new WindowInstance('main');
    Window::fake()->alwaysReturnWindows([$window]);
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    expect($window->toArray()['url'])->toBe(route('overlay.draft-notes'));
});

it('prefers a live draft over a finished one when both exist', function () {
    Draft::factory()->finished()->create();
    $live = Draft::factory()->create(['state' => DraftState::Picking]);

    expect(SyncDraftNotesWindowVisibility::liveDraft()?->id)->toBe($live->id);
});

it('returns the most recently finished draft within grace when none is live', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(120)]);
    $recent = Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(10)]);

    expect(SyncDraftNotesWindowVisibility::liveDraft()?->id)->toBe($recent->id);
});

it('returns null when the only finished draft is past grace', function () {
    Carbon::setTestNow('2026-08-22 12:40:00');
    Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => now()->subSeconds(31)]);

    expect(SyncDraftNotesWindowVisibility::liveDraft())->toBeNull();
});

it('does not touch the window api twice for an unchanged desired state', function () {
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();
    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(1);
});

it('does not touch the window api twice while nothing is live', function () {
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    SyncDraftNotesWindowVisibility::run();
    SyncDraftNotesWindowVisibility::run();

    Window::assertClosedCount(1);
});

it('reopens the window when the desired state flips back', function () {
    $draft = Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();
    $draft->update(['state' => DraftState::Abandoned]);
    SyncDraftNotesWindowVisibility::run();
    $draft->update(['state' => DraftState::Picking]);
    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(2);
    Window::assertClosedCount(0);
});

it('closes an already synced window when the setting flips off and the sync is forced', function () {
    fakeDraftNotesWindowOpen();
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    AppSettings::setShowDraftNotesWindow(false);
    SyncDraftNotesWindowVisibility::run(force: true);

    Window::assertClosed('draft-notes');
});

it('reapplies the same desired state when forced', function () {
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    SyncDraftNotesWindowVisibility::run();
    fakeDraftNotesWindowOpen();
    SyncDraftNotesWindowVisibility::run(force: true);

    Window::assertClosed('draft-notes');
});

it('treats a finished draft with no ended_at as not live', function () {
    Draft::factory()->create(['state' => DraftState::Finished, 'ended_at' => null]);

    expect(SyncDraftNotesWindowVisibility::liveDraft())->toBeNull();

    SyncDraftNotesWindowVisibility::run();

    Window::assertOpenedCount(0);
});

it('logs and swallows a window api failure instead of throwing', function () {
    Log::spy();
    breakWindowApi();
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    Log::shouldHaveReceived('warning')->once()->with('Draft notes window sync failed', ['error' => 'electron is down']);
});

it('retries on the next tick after a window api failure', function () {
    Log::spy();
    breakWindowApi();
    Draft::factory()->create(['state' => DraftState::Picking]);

    SyncDraftNotesWindowVisibility::run();

    // A failure must not be memoized: once the API is back, the very next
    // unforced tick still opens the window.
    Window::fake()->alwaysReturnWindows([new WindowInstance('main')]);
    SyncDraftNotesWindowVisibility::run();

    Window::assertOpened('draft-notes');
});
