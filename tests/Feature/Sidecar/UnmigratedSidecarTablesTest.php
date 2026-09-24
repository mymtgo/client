<?php

use App\Actions\Pipeline\RunPipeline;
use App\Actions\Sidecar\BuildSidecarAgreementSummary;
use App\Actions\Sidecar\IngestSidecarEvents;
use App\Actions\Sidecar\ProjectUnprocessedSidecarEvents;
use App\Actions\Sidecar\ResolveSidecarUsername;
use App\Facades\AppSettings;
use App\Managers\MtgoManager;
use App\Sidecar\SidecarTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A branch build that ships the sidecar code without bumping
 * NATIVEPHP_APP_VERSION runs its version-gated migrations never, so
 * `game_events` and `game_field_diffs` do not exist. Nothing sidecar may
 * throw, log-flood or 500 in that state.
 */
beforeEach(function () {
    $mock = Mockery::mock(MtgoManager::class)->makePartial();
    $mock->shouldReceive('pathsAreValid')->andReturn(true);
    $mock->shouldReceive('ingestLogs')->andReturnNull();
    $mock->shouldReceive('getLogDataPath')->andReturn(sys_get_temp_dir());
    app()->instance('mtgo', $mock);

    SidecarTables::reset();
});

afterEach(function () {
    SidecarTables::reset();
});

function dropSidecarTables(): void
{
    Schema::drop('game_field_diffs');
    Schema::drop('game_events');
    SidecarTables::reset();
}

it('reports the tables as missing and stops caching once they are gone', function () {
    expect(SidecarTables::ready())->toBeTrue();

    dropSidecarTables();

    expect(SidecarTables::ready())->toBeFalse()
        ->and(SidecarTables::ready())->toBeFalse();
});

it('runs a whole pipeline tick with the sidecar tables missing', function () {
    $dir = sys_get_temp_dir().'/sidecar-unmigrated-'.uniqid();
    mkdir($dir);
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    copy(base_path('tests/fixtures/sidecar/status.json'), $dir.'/status.json');
    AppSettings::setSidecarDirectory($dir);
    IngestSidecarEvents::resetTick();

    dropSidecarTables();

    expect(fn () => RunPipeline::run())->not->toThrow(Throwable::class);
    expect(IngestSidecarEvents::run())->toBe(0)
        ->and(ProjectUnprocessedSidecarEvents::run())->toBe(0);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});

it('renders the debug page with the sidecar tables missing', function () {
    AppSettings::setDebugMode(true);

    dropSidecarTables();

    $this->get(route('debug.sidecar.index'))->assertOk();
});

it('returns an all-zero agreement summary with the sidecar tables missing', function () {
    dropSidecarTables();

    $summary = BuildSidecarAgreementSummary::run();

    expect(array_keys($summary))->toBe(['username', 'on_play', 'game_boundaries', 'game_result', 'match_result'])
        ->and($summary['game_result'])->toBe(['agree' => 0, 'disagree' => 0, 'sidecar_incomplete' => 0, 'sidecar_degraded' => 0]);
});

it('resolves no sidecar username with the tables missing and does not throw', function () {
    dropSidecarTables();

    expect(ResolveSidecarUsername::run())->toBeNull();
});

it('is ready again after a reset on a migrated database', function () {
    SidecarTables::reset();

    expect(SidecarTables::ready())->toBeTrue();
});
