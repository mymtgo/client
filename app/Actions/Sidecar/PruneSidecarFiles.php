<?php

namespace App\Actions\Sidecar;

use App\Models\LogInstance;
use App\Sidecar\SidecarPaths;
use Illuminate\Support\Facades\DB;

class PruneSidecarFiles
{
    /**
     * Delete sealed sidecar NDJSON files, and their LogInstance rows, once
     * they are older than the retention window. Never touches the current
     * (unsealed) instance's file, since that one is still being tailed.
     *
     * @return int number of files deleted
     */
    public static function run(int $retentionDays = 30): int
    {
        $dir = SidecarPaths::directory();
        $deleted = 0;

        // Literal % and _ in the configured directory would otherwise act as
        // LIKE wildcards, matching unrelated instances whose file_path only
        // happens to share the pattern's length and literal characters.
        $escapedPrefix = addcslashes($dir.DIRECTORY_SEPARATOR, '\\%_');

        $instances = LogInstance::query()
            ->whereRaw('file_path LIKE ? ESCAPE ?', [$escapedPrefix.'events-%.ndjson', '\\'])
            ->whereNotNull('sealed_at')
            ->where('sealed_at', '<', now()->subDays($retentionDays))
            ->get();

        foreach ($instances as $instance) {
            // Unlink before deleting the row: if the process dies between the
            // two, a sealed row pointing at a missing file is left behind,
            // which the next prune run removes on its own (self-healing). The
            // reverse order would leave an orphaned file with no row, which
            // the ingester would rescan and re-ingest as a brand-new instance.
            if (is_file($instance->file_path) && ! @unlink($instance->file_path)) {
                // Existing file could not be removed (e.g. permissions);
                // leave the row in place and retry on the next run.
                continue;
            }

            DB::transaction(function () use ($instance) {
                $instance->cursor()->delete();
                $instance->delete();
            });

            $deleted++;
        }

        return $deleted;
    }
}
