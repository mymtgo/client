<?php

namespace App\Actions\Pipeline\MetaMessage;

use App\Actions\Logs\ExtractMetaMessageBytes;
use App\Actions\Logs\ParseMetaMessage;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\LogEvent;

/**
 * Walk all LogEvents for a finished game, decode each MetaMessage, and write a
 * GameLog row shaped like ParseGameLogBinary output. Preserves the existing
 * regenerate-card-stats workflow (which reads GameLog.decoded_entries) for
 * games that came in via the live walker rather than a .dat import.
 */
class SynthesizeGameLog
{
    public static function run(Game $game): void
    {
        $matchToken = $game->match?->token;
        if ($matchToken === null) {
            return;
        }

        $entries = LogEvent::query()
            ->where('game_id', $game->mtgo_id)
            ->where('event_type', 'game_management_json')
            ->orderBy('id')
            ->cursor()
            ->map(function (LogEvent $event): ?array {
                $bytes = ExtractMetaMessageBytes::run($event->raw_text ?? '');
                if ($bytes === null) {
                    return null;
                }

                $parsed = ParseMetaMessage::run($bytes);
                if ($parsed === null || $parsed['text'] === null) {
                    return null;
                }

                return [
                    'timestamp' => optional($event->logged_at)->toIso8601String(),
                    'message' => $parsed['text'],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (empty($entries)) {
            return;
        }

        GameLog::updateOrCreate(
            [
                'match_token' => $matchToken,
                'file_path' => 'synthesized:'.$game->mtgo_id,
            ],
            [
                'decoded_entries' => $entries,
                'decoded_at' => now(),
                'first_timestamp' => $entries[0]['timestamp'] ?? null,
            ],
        );
    }
}
