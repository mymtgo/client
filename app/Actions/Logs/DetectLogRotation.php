<?php

namespace App\Actions\Logs;

use App\Models\LogInstance;

class DetectLogRotation
{
    /**
     * @param  array{size: int, ctime: int|null, head_hash: string|null, anchor_hash: string|null}  $observed
     */
    public static function run(LogInstance $instance, array $observed): RotationResult
    {
        $cursor = $instance->cursor;

        if ($cursor && $observed['size'] < (int) $cursor->last_observed_size && $cursor->last_observed_size > 0) {
            return new RotationResult(true, 'truncated');
        }

        if ($instance->file_ctime !== null && $observed['ctime'] !== null && $observed['ctime'] > $instance->file_ctime) {
            return new RotationResult(true, 'ctime_forward');
        }

        if ($observed['head_hash'] !== null && $instance->head_hash !== '' && $observed['head_hash'] !== $instance->head_hash) {
            return new RotationResult(true, 'head_changed');
        }

        if ($instance->anchor_hash !== null && $observed['anchor_hash'] !== null && $observed['anchor_hash'] !== $instance->anchor_hash) {
            return new RotationResult(true, 'anchor_changed');
        }

        return new RotationResult(false, null);
    }
}
