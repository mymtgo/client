<?php

use App\Actions\Sidecar\IngestSidecarEvents;
use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Sidecar\SidecarTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    SidecarTables::reset();
    IngestSidecarEvents::resetTick();
    $this->dir = sys_get_temp_dir().'/sidecar-dispatch-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    copy(base_path('tests/fixtures/sidecar/status.json'), $this->dir.'/status.json');
    AppSettings::setSidecarDirectory($this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Write NDJSON lines using the fixture's envelope, varying only type and seq. */
function writeSidecarLines(string $dir, array $types): void
{
    $template = json_decode(strtok(file_get_contents(base_path('tests/fixtures/sidecar/events-fixture.ndjson')), "\n"), true);
    $file = $dir.'/'.basename(json_decode(file_get_contents($dir.'/status.json'), true)['current_file']);
    $lines = '';

    foreach ($types as $i => $type) {
        $lines .= json_encode(array_merge($template, ['seq' => 1000 + $i, 'type' => $type, 'data' => []]))."\n";
    }

    file_put_contents($file, $lines, FILE_APPEND);
}

it('dispatches one overlay change when lifecycle events are ingested', function () {
    Event::fake([LeagueOverlayChanged::class]);
    writeSidecarLines($this->dir, ['sideboarding_started', 'game_started', 'life_changed']);

    IngestSidecarEvents::run();

    Event::assertDispatchedTimes(LeagueOverlayChanged::class, 1);
});

it('does not dispatch for non-lifecycle events', function () {
    Event::fake([LeagueOverlayChanged::class]);
    writeSidecarLines($this->dir, ['life_changed', 'card_tapped']);

    IngestSidecarEvents::run();

    Event::assertNotDispatched(LeagueOverlayChanged::class);
});
