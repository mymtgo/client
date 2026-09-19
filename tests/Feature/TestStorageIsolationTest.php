<?php

declare(strict_types=1);

use App\Services\Sync\SyncActivity;

it('keeps log writes out of the real storage directory', function () {
    SyncActivity::markQueued();

    $written = (string) config('logging.channels.sync.path');

    expect(storage_path())->not->toBe(base_path('storage'))
        ->and($written)->toStartWith(storage_path())
        ->and(file_get_contents($written))->toContain('Sync queued.');
});
