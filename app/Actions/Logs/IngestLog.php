<?php

namespace App\Actions\Logs;

use App\Models\LogCursor;
use App\Models\LogEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IngestLog
{
    protected static array $ignoredCategories = [
        // ...
    ];

    public static function run(?string $logPath): void
    {
        $lock = cache()->lock('mtgo:ingest:mtgo.log', 10);

        if (! $lock->get()) {
            return;
        }

        try {
            if (! $logPath || ! is_file($logPath)) {
                return;
            }

            $size = @filesize($logPath);
            $mtime = @filemtime($logPath) ?: time();

            if ($size === false) {
                return;
            }

            // compute head_hash (first 4KB)
            $headHash = null;
            $fhHead = @fopen($logPath, 'rb');
            if ($fhHead) {
                $head = (string) @fread($fhHead, 4096);
                @fclose($fhHead);
                if ($head !== '') {
                    $headHash = sha1($head);
                }
            }

            $cursor = LogCursor::firstOrCreate(
                ['file_path' => $logPath],
                ['byte_offset' => 0]
            );

            $truncated = $cursor->byte_offset > $size;
            $replaced = $cursor->head_hash && $headHash && $cursor->head_hash !== $headHash;
            $mtimeBackwards = $cursor->file_mtime && $mtime < $cursor->file_mtime;

            if ($truncated || $replaced || $mtimeBackwards) {
                $cursor->byte_offset = 0;
                $cursor->local_username = null; // clean slate for new log instance
            }

            $cursor->file_mtime = $mtime;
            $cursor->file_size = $size;
            $cursor->head_hash = $headHash;
            $cursor->save();

            // Nothing new since last time.
            if ($cursor->byte_offset >= $size) {
                return;
            }

            $logDate = Carbon::createFromTimestamp($mtime);

            $fh = @fopen($logPath, 'rb');
            if (! $fh) {
                return;
            }

            $rows = [];
            $safeOffset = $cursor->byte_offset;

            try {
                fseek($fh, $cursor->byte_offset);

                $currentEvent = null;
                $eventStartOffset = $cursor->byte_offset;

                while (($line = fgets($fh)) !== false) {
                    $lineEndOffset = ftell($fh);
                    $lineStartOffset = $lineEndOffset - strlen($line);

                    if (static::isNewEventLine($line)) {
                        // Seeing a new event line means the previous event is complete.
                        if ($currentEvent !== null) {
                            $row = static::buildEventRow(
                                $currentEvent,
                                $eventStartOffset,
                                $lineStartOffset,
                                $logPath,
                                $logDate
                            );

                            if ($row) {
                                $rows[] = $row;

                                // Learn username once per log instance
                                if (! $cursor->local_username && $row['category'] === 'UI' && $row['context'] === 'LastLoginName') {
                                    $u = static::extractUsername($row['raw_text']);
                                    if ($u) {
                                        $cursor->local_username = $u;
                                    }
                                }
                            }

                            // safe to advance to the start of this new line
                            $safeOffset = $lineStartOffset;
                        }

                        $currentEvent = $line;
                        $eventStartOffset = $lineStartOffset;
                    } else {
                        if ($currentEvent !== null) {
                            $currentEvent .= $line;
                        }
                    }
                }

                // EOF: only commit last event if complete; otherwise rewind to its start.
                $eofOffset = ftell($fh);

                if ($currentEvent !== null && str_ends_with($currentEvent, "\n")) {
                    $row = static::buildEventRow(
                        $currentEvent,
                        $eventStartOffset,
                        $eofOffset,
                        $logPath,
                        $logDate
                    );

                    if ($row) {
                        $rows[] = $row;

                        // Learn username once per log instance
                        if (! $cursor->local_username && $row['category'] === 'UI' && $row['context'] === 'LastLoginName') {
                            $u = static::extractUsername($row['raw_text']);
                            if ($u) {
                                $cursor->local_username = $u;
                            }
                        }
                    }

                    $safeOffset = $eofOffset;
                } else {
                    $safeOffset = $eventStartOffset;
                }
            } finally {
                fclose($fh);
            }

            // No new complete events? Still update cursor to safeOffset (important if we flushed earlier events).
            if (! empty($rows)) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    LogEvent::query()->insertOrIgnore($chunk);
                }
            }

            $cursor->byte_offset = $safeOffset;
            $cursor->save();
        } finally {
            $lock->release();
        }
    }

    protected static function isNewEventLine(string $line): bool
    {
        return preg_match('/^\d{2}:\d{2}:\d{2} \[(INF|ERR|DBG|WRN|TRC)\]/', $line) === 1;
    }

    protected static function extractUsername(string $raw): ?string
    {
        if (preg_match('/\bUsername=([^\s]+)/', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Returns an insertable row array, or null to skip.
     */
    protected static function buildEventRow(
        string $raw,
        int $start,
        int $end,
        string $logPath,
        Carbon $logDate
    ): ?array {
        $parsed = static::parseHeader($raw);
        if (! $parsed) {
            return null;
        }

        [$timestamp, $level, $category, $context] = $parsed;

        if (! $timestamp) {
            return null;
        }

        if (! empty(static::$ignoredCategories) && in_array($category, static::$ignoredCategories, true)) {
            return null;
        }

        $now = now();

        // Build the model so you can reuse your classifier, then convert to row.
        $event = (new LogEvent)->fill([
            'file_path' => $logPath,
            'byte_offset_start' => $start,
            'byte_offset_end' => $end,
            'timestamp' => $timestamp,
            'level' => $level,
            'category' => $category,
            'context' => $context,
            'raw_text' => trim($raw),
            'ingested_at' => $now,
            'event_type' => null,
            'logged_at' => $logDate,
        ]);

        $event = ClassifyLogEvent::run($event);

        // Convert to DB row (avoid model->save() to keep locks minimal).
        return [
            'file_path' => $event->file_path,
            'byte_offset_start' => $event->byte_offset_start,
            'byte_offset_end' => $event->byte_offset_end,
            'timestamp' => $event->timestamp,
            'level' => $event->level,
            'category' => $event->category,
            'context' => $event->context,
            'raw_text' => $event->raw_text,
            'ingested_at' => $event->ingested_at,
            'event_type' => $event->event_type,
            'logged_at' => $event->logged_at,
            'match_id' => $event->match_id,
            'match_token' => $event->match_token,
            'game_id' => $event->game_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected static function parseHeader(string $raw): ?array
    {
        preg_match(
            '/^(?<time>\d{2}:\d{2}:\d{2}) \[(?<level>\w+)\] \((?<cat>[^|]+)\|(?<ctx>[^\)]+)\)/',
            $raw,
            $m
        );

        if (empty($m)) {
            return null;
        }

        return [
            $m['time'] ?? null,
            $m['level'] ?? null,
            $m['cat'] ?? null,
            $m['ctx'] ?? null,
        ];
    }
}
