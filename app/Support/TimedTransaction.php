<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimedTransaction
{
    /**
     * Run a DB transaction and log if it exceeds the threshold.
     *
     * Pipeline write-lock contention shows up as `database is locked` errors
     * on queue workers. This helper surfaces the actual culprit by recording
     * how long each long-held transaction runs. Threshold default is 1000ms
     * because anything past that risks queue worker timeouts under load.
     */
    public static function run(string $label, Closure $callback, int $thresholdMs = 1000): mixed
    {
        $start = hrtime(true);

        try {
            return DB::transaction($callback);
        } finally {
            $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

            if ($durationMs >= $thresholdMs) {
                Log::channel('pipeline')->warning("Long transaction: {$label} held write lock for {$durationMs}ms", [
                    'label' => $label,
                    'duration_ms' => $durationMs,
                ]);
            }
        }
    }
}
