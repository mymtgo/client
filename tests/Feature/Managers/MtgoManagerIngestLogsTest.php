<?php

use App\Managers\MtgoManager;
use App\Models\LogInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('keeps ingesting the remaining log files when one of them throws', function () {
    $bad = '/nowhere/first/mtgo.log';
    $good = sys_get_temp_dir().'/mtgo_isolated_'.bin2hex(random_bytes(4)).'.log';
    file_put_contents($good, "12:00:00 [INF] (Login|MtGO Login Success) Username: SomeUser\n");

    Cache::put('mtgo.all_log_paths', [$bad, $good], now()->addMinute());

    $manager = Mockery::mock(MtgoManager::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $manager->shouldReceive('canRun')->andReturn(true);
    $manager->shouldReceive('ingestLogInstance')->with($bad)->once()->andThrow(new RuntimeException('database is locked'));
    $manager->shouldReceive('ingestLogInstance')->with($good)->passthru();

    try {
        $manager->ingestLogs();
    } finally {
        @unlink($good);
    }

    expect(LogInstance::where('file_path', $good)->exists())->toBeTrue();
});
