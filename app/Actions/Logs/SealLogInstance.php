<?php

namespace App\Actions\Logs;

use App\Events\LogInstanceSealed;
use App\Models\LogInstance;

class SealLogInstance
{
    public static function run(LogInstance $instance, string $reason): void
    {
        if ($instance->isSealed()) {
            return;
        }

        $instance->update([
            'sealed_at' => now(),
            'seal_reason' => $reason,
        ]);

        LogInstanceSealed::dispatch($instance->id, $reason);
    }
}
