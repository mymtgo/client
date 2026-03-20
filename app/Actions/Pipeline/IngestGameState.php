<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Facades\Mtgo;
use App\Models\GameLogCursor;
use App\Models\LogEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestGameState
{
    /**
     * Parse a binary game log .dat file and persist new LogEvents from it.
     */
    public static function run(string $datPath): void
    {
        if (! is_file($datPath)) {
            return;
        }

        $raw = @file_get_contents($datPath);

        if ($raw === false || $raw === '') {
            return;
        }

        $cursor = GameLogCursor::firstOrCreate(
            ['file_path' => $datPath],
            ['byte_offset' => 0]
        );

        $matchToken = self::extractMatchToken($datPath);

        if ($matchToken && ! $cursor->match_token) {
            $cursor->update(['match_token' => $matchToken]);
        }

        $startingOffset = $cursor->byte_offset;

        $parsed = ParseGameLogBinary::run($raw, $cursor->byte_offset ?: null);

        if (! $parsed || empty($parsed['entries'])) {
            return;
        }

        $newByteOffset = $parsed['byte_offset'];

        if ($newByteOffset <= $startingOffset) {
            return;
        }

        $localPlayer = Mtgo::getUsername();
        $rows = [];
        $now = now();
        // Each row needs a unique byte_offset_start due to the
        // (file_path, byte_offset_start) unique index. We use a
        // sequential counter starting from the cursor position.
        $syntheticOffset = $startingOffset;

        if ($localPlayer) {
            $gameResults = ExtractGameResults::run($parsed['entries'], $localPlayer);

            foreach ($gameResults['results'] as $index => $won) {
                $rows[] = [
                    'file_path' => $datPath,
                    'byte_offset_start' => $syntheticOffset++,
                    'byte_offset_end' => $newByteOffset,
                    'timestamp' => $now->toTimeString(),
                    'level' => 'INF',
                    'category' => 'GameLog',
                    'context' => 'game_result',
                    'raw_text' => json_encode([
                        'won' => $won,
                        'game_index' => $index,
                        'source' => 'binary_game_log',
                    ]),
                    'ingested_at' => $now,
                    'event_type' => 'game_result',
                    'logged_at' => $now,
                    'match_token' => $matchToken,
                    'match_id' => null,
                    'game_id' => null,
                    'username' => $localPlayer,
                    'processed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Extract card reveals for opponent (for progressive archetype detection)
            foreach ($parsed['entries'] as $entry) {
                $cardReveal = self::extractCardReveal($entry['message'], $localPlayer);

                if ($cardReveal) {
                    $rows[] = [
                        'file_path' => $datPath,
                        'byte_offset_start' => $syntheticOffset++,
                        'byte_offset_end' => $newByteOffset,
                        'timestamp' => $entry['timestamp'] ?? $now->toTimeString(),
                        'level' => 'INF',
                        'category' => 'GameLog',
                        'context' => 'card_revealed',
                        'raw_text' => json_encode($cardReveal),
                        'ingested_at' => $now,
                        'event_type' => 'card_revealed',
                        'logged_at' => $now,
                        'match_token' => $matchToken,
                        'match_id' => null,
                        'game_id' => null,
                        'username' => $localPlayer,
                        'processed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        DB::transaction(function () use ($rows, $cursor, $newByteOffset) {
            if (! empty($rows)) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    LogEvent::query()->insertOrIgnore($chunk);
                }
            }

            $cursor->update(['byte_offset' => $newByteOffset]);
        });

        if (! empty($rows)) {
            $newEvents = LogEvent::where('file_path', $cursor->file_path)
                ->where('byte_offset_start', '>=', $startingOffset)
                ->get();

            DispatchDomainEvents::run($newEvents);
        }

        Log::channel('pipeline')->info("IngestGameState: processed {$datPath}", [
            'new_events' => count($rows),
            'byte_range' => "{$startingOffset} → {$newByteOffset}",
        ]);
    }

    /**
     * Extract a card reveal from a game log message.
     *
     * Matches patterns like:
     *
     *   @P{player} casts @[CardName@:id,id:@]
     *
     *   @P{player} plays @[CardName@:id,id:@]
     *
     * Returns null if not a card reveal or if it's the local player's card.
     *
     * @return array{player: string, card_name: string, action: string}|null
     */
    private static function extractCardReveal(string $message, string $localPlayer): ?array
    {
        // Pattern: @P{player} casts/plays @[CardName@:digits,digits:@]
        if (! preg_match('/^@P(\w+) (casts|plays) @\[([^@]+)@:\d+,\d+:@\]/', $message, $m)) {
            return null;
        }

        $player = $m[1];
        $action = $m[2];
        $cardName = trim($m[3]);

        // Skip local player's cards — we only need opponent reveals
        if ($player === $localPlayer) {
            return null;
        }

        return [
            'player' => $player,
            'card_name' => $cardName,
            'action' => $action,
        ];
    }

    /**
     * Extract the match token from a filename like Match_GameLog_{TOKEN}.dat.
     */
    private static function extractMatchToken(string $path): ?string
    {
        $basename = basename($path);

        if (preg_match('/Match_GameLog_(.+)\.dat/', $basename, $m)) {
            return $m[1];
        }

        return null;
    }
}
