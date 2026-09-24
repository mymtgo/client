<?php

namespace App\Actions\Sidecar;

use App\Actions\Logs\SealLogInstance;
use App\Models\GameEvent;
use App\Models\LogCursor;
use App\Models\LogInstance;
use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarTables;
use App\Sidecar\UnsupportedSidecarSchemaException;
use App\Support\TimedTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class IngestSidecarEvents
{
    /** Ordinary ticks tail only status.json's current_file; every Nth tick rescans the directory. */
    public const RESCAN_EVERY = 30;

    public const MAX_BYTES_PER_TICK = 4 * 1024 * 1024;

    private static int $tick = 0;

    private static ?string $lastCurrentFile = null;

    public static function resetTick(): void
    {
        self::$tick = 0;
        self::$lastCurrentFile = null;
    }

    /**
     * @return int rows inserted this tick
     */
    public static function run(): int
    {
        $dir = SidecarPaths::directory();

        // Directory check first so a machine with no sidecar at all (macOS,
        // dev) still adds zero queries per tick, which is the invariant the
        // design leans on.
        if (! is_dir($dir)) {
            return 0;
        }

        if (! SidecarTables::ready()) {
            return 0;
        }

        self::$tick++;

        $status = ReadSidecarStatus::run();
        $current = $status?->currentFile ? $dir.DIRECTORY_SEPARATOR.basename($status->currentFile) : null;

        $currentChanged = $current !== self::$lastCurrentFile;
        self::$lastCurrentFile = $current;

        $files = ($currentChanged || self::$tick % self::RESCAN_EVERY === 1)
            ? SidecarPaths::eventFiles()
            : array_values(array_filter([$current], fn ($f) => $f !== null));

        $inserted = 0;

        // When status.json is missing or unreadable, $current is null and no file
        // can be identified as "the one still being written". Sealing a file as
        // session_rotated just because it happens to be fully caught up on this
        // tick would be wrong in that case, since it might be the live file, and
        // once sealed it is never read again. Only make that call when we
        // actually know which file is current.
        $currentKnown = $current !== null;

        foreach ($files as $path) {
            try {
                $inserted += self::ingestFile($path, isCurrent: $path === $current, currentKnown: $currentKnown);
            } catch (\Throwable $e) {
                Log::channel('pipeline')->error('IngestSidecarEvents: skipping file after error', [
                    'file' => $path,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $inserted;
    }

    private static function ingestFile(string $path, bool $isCurrent, bool $currentKnown): int
    {
        $instance = LogInstance::query()
            ->where('file_path', $path)
            ->whereNull('sealed_at')
            ->with('cursor')
            ->first();

        $size = @filesize($path);

        if ($size === false) {
            return 0;
        }

        if ($instance === null) {
            if (LogInstance::query()->where('file_path', $path)->whereNotNull('sealed_at')->exists()) {
                return 0;
            }

            $instance = LogInstance::create([
                'file_path' => $path,
                'identity_hash' => sha1($path.':'.(@filectime($path) ?: 0)),
                'file_ctime' => @filectime($path) ?: null,
                'head_hash' => sha1((string) @file_get_contents($path, false, null, 0, 4096)),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        $cursor = $instance->cursor ?? self::createCursor($instance);

        if ($size < $cursor->byte_offset) {
            SealLogInstance::run($instance, 'truncated');

            return 0;
        }

        $inserted = 0;

        if ($size > $cursor->byte_offset) {
            $inserted = self::readFrom($path, $instance, $cursor, $size);
        }

        $instance->last_seen_at = now();
        $instance->save();

        if ($currentKnown && ! $isCurrent && $cursor->fresh()->byte_offset >= $size && ! $instance->fresh()->isSealed()) {
            SealLogInstance::run($instance, 'session_rotated');
        }

        return $inserted;
    }

    private static function readFrom(string $path, LogInstance $instance, LogCursor $cursor, int $size): int
    {
        $fh = @fopen($path, 'rb');

        if (! $fh) {
            return 0;
        }

        $rows = [];
        $safeOffset = $cursor->byte_offset;
        $sealReason = null;

        try {
            fseek($fh, $cursor->byte_offset);

            while (($line = fgets($fh)) !== false) {
                $end = ftell($fh);

                if (! str_ends_with($line, "\n")) {
                    break;
                }

                try {
                    $parsed = ParseSidecarLine::run($line);
                } catch (UnsupportedSidecarSchemaException $e) {
                    $sealReason = 'unsupported_schema';
                    Log::channel('pipeline')->error('IngestSidecarEvents: '.$e->getMessage(), ['file' => $path]);
                    break;
                } catch (\JsonException|\InvalidArgumentException $e) {
                    Log::channel('pipeline')->warning('IngestSidecarEvents: skipping malformed line', [
                        'file' => $path,
                        'offset' => $safeOffset,
                        'message' => $e->getMessage(),
                    ]);
                    $safeOffset = $end;

                    continue;
                }

                if ($parsed !== null) {
                    $rows[] = $parsed->toRow($instance->id);
                }

                $safeOffset = $end;

                if ($safeOffset - $cursor->byte_offset >= self::MAX_BYTES_PER_TICK) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        $inserted = 0;

        foreach (array_chunk($rows, 500) as $i => $chunk) {
            $inserted += TimedTransaction::run("IngestSidecarEvents:chunk[{$i}]", fn () => GameEvent::query()->insertOrIgnore($chunk));
        }

        if ($safeOffset > $cursor->byte_offset) {
            $cursor->byte_offset = $safeOffset;
            $cursor->last_advance_at = now();
            $cursor->stuck_ticks = 0;
        }

        $cursor->last_observed_size = $size;
        $cursor->save();

        if ($sealReason !== null) {
            SealLogInstance::run($instance, $sealReason);
        }

        return $inserted;
    }

    private static function createCursor(LogInstance $instance): LogCursor
    {
        try {
            return LogCursor::create(['log_instance_id' => $instance->id]);
        } catch (UniqueConstraintViolationException) {
            return LogCursor::where('log_instance_id', $instance->id)->firstOrFail();
        }
    }
}
