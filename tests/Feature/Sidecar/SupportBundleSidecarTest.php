<?php

use App\Actions\Support\BuildSupportBundle;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameFieldDiff;
use App\Models\MtgoMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Isolate FindMtgoLogPath from the host machine's real MTGO install path.
    $emptyMtgoDir = sys_get_temp_dir().'/mtgo-empty-'.uniqid();
    mkdir($emptyMtgoDir);
    Mtgo::shouldReceive('getLogPath')->andReturn($emptyMtgoDir);
    Cache::forget('mtgo.all_log_paths');
});

it('adds sidecar files and a diff export to the bundle', function () {
    $logs = sys_get_temp_dir().'/support-logs-'.uniqid();
    mkdir($logs);
    file_put_contents($logs.'/laravel.log', 'x');

    $dir = sys_get_temp_dir().'/sidecar-bundle-'.uniqid();
    mkdir($dir);
    copy(base_path('tests/fixtures/sidecar/status.json'), $dir.'/status.json');
    copy(base_path('tests/fixtures/sidecar/events-fixture.ndjson'), $dir.'/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson');
    file_put_contents($dir.'/sidecar.log', 'hello');
    AppSettings::setSidecarDirectory($dir);

    $match = MtgoMatch::factory()->create(['mtgo_id' => '1']);
    $game = Game::factory()->create(['match_id' => $match->id, 'mtgo_id' => '2']);
    GameFieldDiff::create(['match_id' => $match->id, 'game_id' => $game->id, 'field' => 'game_result', 'log_value' => ['winner' => 'a'], 'sidecar_value' => ['winner' => 'b'], 'chosen_source' => 'log']);

    $zipPath = (new BuildSupportBundle(logsDir: $logs))();
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $diffs = json_decode($zip->getFromName('sidecar/game_field_diffs.json'), true);
    $zip->close();

    expect($names)->toContain('sidecar/status.json', 'sidecar/sidecar.log', 'sidecar/events-aaaaaaaa-0000-0000-0000-000000000001.ndjson', 'sidecar/game_field_diffs.json')
        ->and($diffs[0]['match_mtgo_id'])->toBe('1')
        ->and($diffs[0]['game_mtgo_id'])->toBe('2')
        ->and($diffs[0]['field'])->toBe('game_result');

    unlink($zipPath);
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
    array_map('unlink', glob($logs.'/*'));
    rmdir($logs);
});

it('does not fail when the sidecar directory is absent', function () {
    $logs = sys_get_temp_dir().'/support-logs-'.uniqid();
    mkdir($logs);
    file_put_contents($logs.'/laravel.log', 'x');
    AppSettings::setSidecarDirectory(sys_get_temp_dir().'/none-'.uniqid());

    $zipPath = (new BuildSupportBundle(logsDir: $logs))();

    expect(is_file($zipPath))->toBeTrue();
    unlink($zipPath);
    array_map('unlink', glob($logs.'/*'));
    rmdir($logs);
});
