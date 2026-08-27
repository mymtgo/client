<?php

use App\Actions\Overlay\SyncDraftNotesWindowVisibility;
use App\Actions\Pipeline\RunPipeline;
use App\Enums\DraftState;
use App\Facades\AppSettings;
use App\Models\Draft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

uses(RefreshDatabase::class);

beforeEach(fn () => mockMtgoManagerForPipeline());

it('opens the draft notes window from the pipeline tick while a draft is picking', function () {
    Draft::factory()->create(['state' => DraftState::Picking]);

    RunPipeline::run();

    Window::assertOpened('draft-notes');
});

it('closes the draft notes window from the pipeline tick once the draft is abandoned', function () {
    Window::fake()->alwaysReturnWindows([
        new WindowInstance('main'),
        new WindowInstance('draft-notes'),
    ]);
    Draft::factory()->create(['state' => DraftState::Abandoned]);

    RunPipeline::run();

    Window::assertClosed('draft-notes');
});

it('does not open the draft notes window from the pipeline when the setting is off', function () {
    AppSettings::setShowDraftNotesWindow(false);
    Draft::factory()->create(['state' => DraftState::Picking]);

    RunPipeline::run();

    Window::assertOpenedCount(0);
});

it('opens the notes window for the hobbit draft while inside grace and closes it after', function () {
    AppSettings::setSystemTimezone('Europe/London');
    ingestFixtureLog('mtgo_draft_hobbit.log');
    runPipelineUntilIdle();

    $draft = Draft::query()->where('state', DraftState::Finished)->firstOrFail();
    expect($draft->ended_at)->not->toBeNull();

    // Pretend the tick runs ten seconds after the draft ended: window stays.
    Carbon::setTestNow($draft->ended_at->copy()->addSeconds(10));
    Window::fake()->alwaysReturnWindows([new WindowInstance('main')]);
    RunPipeline::run();
    Window::assertOpened('draft-notes');

    $this->get(route('overlay.draft-notes'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('notes.draftId', $draft->id)
        ->where('notes.state', 'finished')
        ->where('notes.currentOrdinal', 42)
        // The whole draft ships, oldest first, so the window can step back
        // and pick up notes retroactively inside the grace period.
        ->has('notes.picks', 42)
        ->where('notes.picks.0.label', 'P1p1')
        ->where('notes.picks.41.label', 'P3p14'));

    // Past grace: the next tick closes it.
    Carbon::setTestNow($draft->ended_at->copy()->addSeconds(SyncDraftNotesWindowVisibility::GRACE_SECONDS + 1));
    Window::fake()->alwaysReturnWindows([new WindowInstance('main'), new WindowInstance('draft-notes')]);
    RunPipeline::run();
    Window::assertClosed('draft-notes');
});

it('completes the pipeline tick when the window api is unreachable', function () {
    $mock = Mockery::mock(Window::getFacadeRoot())->makePartial();
    $mock->shouldReceive('all')->andThrow(new ConnectionException('electron is down'));
    Window::swap($mock);
    Draft::factory()->create(['state' => DraftState::Picking]);

    RunPipeline::run();
})->throwsNoExceptions();
