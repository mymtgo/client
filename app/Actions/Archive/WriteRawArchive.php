<?php

namespace App\Actions\Archive;

use App\Models\RawArchive;
use Illuminate\Support\Facades\Storage;

/**
 * The private durability floor: gzip a match's raw log segment to the local
 * `archive` disk and index it. Append-only, kept forever, never uploaded —
 * raw logs carry opponent handles and chat and must not leave the device.
 */
final class WriteRawArchive
{
    public function run(string $matchKey, string $rawSegment): void
    {
        if ($rawSegment === '') {
            return;
        }

        $capturedAt = now();
        $path = sprintf('%s/%s.log.gz', $matchKey, $capturedAt->format('Ymd_His_u'));

        Storage::disk('archive')->put($path, gzencode($rawSegment, 9));

        RawArchive::create([
            'match_key' => $matchKey,
            'path' => $path,
            'captured_at' => $capturedAt,
            'byte_len' => strlen($rawSegment),
        ]);
    }
}
