<?php

namespace App\Actions\Logs;

use App\Models\Account;
use App\Models\LogCursor;
use App\Models\LogEvent;
use App\Models\LogInstance;
use App\Support\TimedTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class IngestLogInstance
{
    public const STUCK_THRESHOLD = 60;

    protected static array $ignoredCategories = [
        // Mirror IngestLog::$ignoredCategories — empty in the source file.
    ];

    public static function run(?string $logPath): void
    {
        if (! $logPath || ! is_file($logPath)) {
            return;
        }

        $observed = static::observe($logPath);
        if ($observed === null) {
            return;
        }

        $instance = static::resolveActiveInstance($logPath, $observed);
        if ($instance === null) {
            return; // sealed only; new instance created on next tick
        }

        $cursor = $instance->cursor ?? LogCursor::create(['log_instance_id' => $instance->id]);

        // Stuck-tick force-reset before ingesting.
        if ($cursor->stuck_ticks >= self::STUCK_THRESHOLD) {
            Log::channel('pipeline')->error('Cursor stuck past threshold — force-sealing instance', [
                'instance_id' => $instance->id,
                'stuck_ticks' => $cursor->stuck_ticks,
                'file' => $logPath,
            ]);
            SealLogInstance::run($instance, 'stuck_force_reset');

            return;
        }

        $hadAdvance = static::ingestBytes($logPath, $instance, $cursor, $observed);

        if (! $hadAdvance && $observed['size'] > $cursor->last_observed_size) {
            $cursor->increment('stuck_ticks');
        } elseif ($hadAdvance) {
            $cursor->stuck_ticks = 0;
            $cursor->last_advance_at = now();
        }

        $cursor->last_observed_size = $observed['size'];
        $cursor->save();

        $instance->last_seen_at = now();
        $instance->save();
    }

    /**
     * Compute file signature: size, mtime, ctime, head_hash, anchor_hash.
     *
     * @return array{size:int, mtime:int, ctime:int|null, head_hash:string|null, anchor_hash:string|null}|null
     */
    protected static function observe(string $path): ?array
    {
        $stat = @stat($path);
        if ($stat === false) {
            return null;
        }

        $size = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];
        $ctime = isset($stat['ctime']) ? (int) $stat['ctime'] : null;

        $headHash = null;
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $head = (string) @fread($fh, 4096);
            @fclose($fh);
            if ($head !== '') {
                $headHash = sha1($head);
            }
        }

        return [
            'size' => $size,
            'mtime' => $mtime,
            'ctime' => $ctime,
            'head_hash' => $headHash,
            'anchor_hash' => null, // populated by resolveActiveInstance when an anchor exists
        ];
    }

    protected static function readAnchorHash(string $path, int $offset): ?string
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return null;
        }

        @fseek($fh, $offset);
        $bytes = (string) @fread($fh, 4096);
        @fclose($fh);

        return $bytes === '' ? null : sha1($bytes);
    }

    /**
     * Return the active (unsealed) instance for this path after rotation check.
     */
    protected static function resolveActiveInstance(string $path, array &$observed): ?LogInstance
    {
        $instance = LogInstance::query()
            ->where('file_path', $path)
            ->whereNull('sealed_at')
            ->with('cursor')
            ->first();

        if ($instance !== null) {
            if ($instance->anchor_offset !== null) {
                $observed['anchor_hash'] = static::readAnchorHash($path, (int) $instance->anchor_offset);
            }

            // While the file is still <4KB the head_hash (sha1 of first 4096
            // bytes) naturally shifts as the file grows — every append
            // changes it. Refresh the instance's recorded head_hash until
            // the head stabilises at 4KB. Once the file reaches 4KB the head
            // is frozen and any subsequent change is a real "first 4KB
            // rewritten" signal worth treating as rotation.
            if ($observed['size'] < 4096 && $observed['head_hash'] !== null && $instance->head_hash !== $observed['head_hash']) {
                $instance->head_hash = $observed['head_hash'];
                $instance->save();
            }

            $result = DetectLogRotation::run($instance, $observed);

            if ($result->rotated) {
                Log::channel('pipeline')->info('Rotation detected — sealing instance', [
                    'instance_id' => $instance->id,
                    'reason' => $result->reason,
                    'file' => $path,
                ]);
                SealLogInstance::run($instance, $result->reason);
                $instance = null;
            }
        }

        if ($instance === null) {
            $instance = LogInstance::create([
                'file_path' => $path,
                'identity_hash' => sha1(($observed['head_hash'] ?? '').':'.($observed['ctime'] ?? 0).':'.$observed['size']),
                'file_ctime' => $observed['ctime'],
                'head_hash' => $observed['head_hash'] ?? '',
                'anchor_offset' => null,
                'anchor_hash' => null,
                'tail_hash' => null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return $instance;
    }

    /**
     * Read new bytes, parse events, commit. Returns true if cursor advanced.
     */
    protected static function ingestBytes(string $logPath, LogInstance $instance, LogCursor $cursor, array $observed): bool
    {
        if ($cursor->byte_offset >= $observed['size']) {
            return false;
        }

        $logDate = Carbon::createFromTimestamp($observed['mtime']);

        $fh = @fopen($logPath, 'rb');
        if (! $fh) {
            return false;
        }

        $rows = [];
        $safeOffset = $cursor->byte_offset;
        $currentUsername = $instance->local_username;
        $localUsernameChanged = false;

        try {
            fseek($fh, $cursor->byte_offset);

            $currentEvent = null;
            $eventStartOffset = $cursor->byte_offset;

            while (($line = fgets($fh)) !== false) {
                $lineEndOffset = ftell($fh);
                $lineStartOffset = $lineEndOffset - strlen($line);

                if (static::isNewEventLine($line)) {
                    if ($currentEvent !== null) {
                        $row = static::buildEventRow($currentEvent, $eventStartOffset, $lineStartOffset, $logPath, $logDate, $instance->id);
                        if ($row) {
                            if (static::detectLoginInRow($row, $currentUsername)) {
                                $localUsernameChanged = true;
                            }
                            $row['username'] = $currentUsername;
                            $rows[] = $row;
                        }
                        $safeOffset = $lineStartOffset;
                    }
                    $currentEvent = $line;
                    $eventStartOffset = $lineStartOffset;
                } elseif ($currentEvent !== null) {
                    $currentEvent .= $line;
                }
            }

            $eofOffset = ftell($fh);

            if ($currentEvent !== null && str_ends_with($currentEvent, "\n")) {
                $row = static::buildEventRow($currentEvent, $eventStartOffset, $eofOffset, $logPath, $logDate, $instance->id);
                if ($row) {
                    if (static::detectLoginInRow($row, $currentUsername)) {
                        $localUsernameChanged = true;
                    }
                    $row['username'] = $currentUsername;
                    $rows[] = $row;
                }
                $safeOffset = $eofOffset;
            } else {
                $safeOffset = $eventStartOffset;
            }
        } finally {
            fclose($fh);
        }

        if (! empty($rows)) {
            foreach (array_chunk($rows, 500) as $i => $chunk) {
                TimedTransaction::run("IngestLogInstance:chunk[{$i}]", function () use ($chunk) {
                    LogEvent::query()->insertOrIgnore($chunk);
                });
            }
        }

        $advanced = $safeOffset > $cursor->byte_offset;

        if ($advanced) {
            TimedTransaction::run('IngestLogInstance:cursor', function () use ($cursor, $safeOffset, $logPath) {
                $cursor->byte_offset = $safeOffset;
                // Choose a verify anchor inside the newly-committed region.
                $newAnchorOffset = (int) max(0, min($safeOffset / 2, 16 * 1024));
                $cursor->verify_anchor_offset = $newAnchorOffset;
                $cursor->verify_anchor_hash = static::readAnchorHash($logPath, $newAnchorOffset);
                $cursor->save();
            });
        }

        if ($localUsernameChanged) {
            $instance->local_username = $currentUsername;
            // Seed the instance anchor for future rotation checks.
            $instance->anchor_offset = (int) max(0, min($safeOffset / 2, 16 * 1024));
            $instance->anchor_hash = static::readAnchorHash($logPath, (int) $instance->anchor_offset);
            $instance->save();
        }

        return $advanced;
    }

    protected static function isNewEventLine(string $line): bool
    {
        return preg_match('/^\d{2}:\d{2}:\d{2} \[(INF|ERR|DBG|WRN|TRC)\]/', $line) === 1;
    }

    protected static function detectLoginInRow(array $row, ?string &$currentUsername): bool
    {
        if ($row['category'] !== 'Login' || $row['context'] !== 'MtGO Login Success') {
            return false;
        }

        if (preg_match('/Username:\s*(\S+)/', $row['raw_text'], $m)) {
            $currentUsername = $m[1];
            Account::registerAndActivate($currentUsername);

            return true;
        }

        return false;
    }

    protected static function buildEventRow(
        string $raw,
        int $start,
        int $end,
        string $logPath,
        Carbon $logDate,
        int $instanceId
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

        $event = (new LogEvent)->fill([
            'log_instance_id' => $instanceId,
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

        if (! $event->event_type && $category !== 'Login') {
            return null;
        }

        return [
            'log_instance_id' => $instanceId,
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
            'tournament_token' => $event->tournament_token,
            'game_id' => $event->game_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    protected static function parseHeader(string $raw): ?array
    {
        preg_match(
            '/^(?<time>\d{2}:\d{2}:\d{2}) \[(?<level>\w+)\] \((?<cat>[^|]+)\|(?<ctx>[^\)]*)\)/',
            $raw,
            $m
        );

        if (empty($m)) {
            return null;
        }

        return [$m['time'], $m['level'], $m['cat'], $m['ctx']];
    }
}
