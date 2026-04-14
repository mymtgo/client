<?php

use App\Actions\Support\BuildSupportBundle;
use App\Exceptions\SupportBundleEmptyException;
use App\Facades\Mtgo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mtgoLogDir = sys_get_temp_dir().'/mtgo-test-'.uniqid();
    File::makeDirectory($this->mtgoLogDir, 0755, true);
    File::put($this->mtgoLogDir.'/mtgo.log', "MTGO log contents\n");

    $this->logsDir = sys_get_temp_dir().'/logs-test-'.uniqid();
    File::makeDirectory($this->logsDir, 0755, true);
    File::put($this->logsDir.'/pipeline-2026-04-13.log', "Pipeline log contents\n");
    File::put($this->logsDir.'/laravel.log', "Laravel log contents\n");

    Mtgo::shouldReceive('getLogPath')->andReturn($this->mtgoLogDir);
    Cache::forget('mtgo.all_log_paths');
});

afterEach(function () {
    File::deleteDirectory($this->mtgoLogDir);
    File::deleteDirectory($this->logsDir);
    Cache::forget('mtgo.all_log_paths');
});

it('zips the mtgo log, laravel log, and the latest pipeline log', function () {
    $zipPath = (new BuildSupportBundle($this->logsDir))();

    expect(file_exists($zipPath))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)->toBe(3);
    expect($zip->getFromName('mtgo.log'))->toBe("MTGO log contents\n");
    expect($zip->getFromName('laravel.log'))->toBe("Laravel log contents\n");
    expect($zip->getFromName('pipeline-2026-04-13.log'))->toBe("Pipeline log contents\n");

    $zip->close();
    File::delete($zipPath);
});

it('zips only the mtgo log when no pipeline or laravel logs exist', function () {
    File::delete($this->logsDir.'/pipeline-2026-04-13.log');
    File::delete($this->logsDir.'/laravel.log');

    $zipPath = (new BuildSupportBundle($this->logsDir))();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('mtgo.log'))->toBe("MTGO log contents\n");

    $zip->close();
    File::delete($zipPath);
});

it('zips only the pipeline log when no mtgo or laravel logs exist', function () {
    File::delete($this->mtgoLogDir.'/mtgo.log');
    File::delete($this->logsDir.'/laravel.log');
    Cache::forget('mtgo.all_log_paths');

    $zipPath = (new BuildSupportBundle($this->logsDir))();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('pipeline-2026-04-13.log'))->toBe("Pipeline log contents\n");

    $zip->close();
    File::delete($zipPath);
});

it('zips only the laravel log when no mtgo or pipeline logs exist', function () {
    File::delete($this->mtgoLogDir.'/mtgo.log');
    File::delete($this->logsDir.'/pipeline-2026-04-13.log');
    Cache::forget('mtgo.all_log_paths');

    $zipPath = (new BuildSupportBundle($this->logsDir))();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    expect($zip->numFiles)->toBe(1);
    expect($zip->getFromName('laravel.log'))->toBe("Laravel log contents\n");

    $zip->close();
    File::delete($zipPath);
});

it('throws SupportBundleEmptyException when no logs exist', function () {
    File::delete($this->mtgoLogDir.'/mtgo.log');
    File::delete($this->logsDir.'/pipeline-2026-04-13.log');
    File::delete($this->logsDir.'/laravel.log');
    Cache::forget('mtgo.all_log_paths');

    expect(fn () => (new BuildSupportBundle($this->logsDir))())
        ->toThrow(SupportBundleEmptyException::class);
});

it('includes up to three most recent pipeline logs', function () {
    File::put($this->logsDir.'/pipeline-2026-04-12.log', "Day 2\n");
    File::put($this->logsDir.'/pipeline-2026-04-11.log', "Day 3\n");

    $zipPath = (new BuildSupportBundle($this->logsDir))();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    // mtgo.log + laravel.log + 3 pipeline logs
    expect($zip->numFiles)->toBe(5);
    expect($zip->getFromName('pipeline-2026-04-13.log'))->toBe("Pipeline log contents\n");
    expect($zip->getFromName('pipeline-2026-04-12.log'))->toBe("Day 2\n");
    expect($zip->getFromName('pipeline-2026-04-11.log'))->toBe("Day 3\n");

    $zip->close();
    File::delete($zipPath);
});

it('drops pipeline logs older than the three most recent', function () {
    File::put($this->logsDir.'/pipeline-2026-04-12.log', "Day 2\n");
    File::put($this->logsDir.'/pipeline-2026-04-11.log', "Day 3\n");
    File::put($this->logsDir.'/pipeline-2026-04-10.log', "Day 4 (excluded)\n");
    File::put($this->logsDir.'/pipeline-2026-04-09.log', "Day 5 (excluded)\n");

    $zipPath = (new BuildSupportBundle($this->logsDir))();

    $zip = new ZipArchive;
    $zip->open($zipPath);

    // mtgo.log + laravel.log + 3 pipeline logs (limit enforced)
    expect($zip->numFiles)->toBe(5);
    expect($zip->getFromName('pipeline-2026-04-10.log'))->toBeFalse();
    expect($zip->getFromName('pipeline-2026-04-09.log'))->toBeFalse();

    $zip->close();
    File::delete($zipPath);
});
