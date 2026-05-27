<?php

namespace App\Actions\Matches;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Util\ExtractJson;
use App\Models\LogEvent;

class ExtractMetaMessageEntries
{
    /**
     * Build the {timestamp, message}[] entries shape for a match from its
     * MetaMessage log events. Same shape ExtractGameResults consumes.
     *
     * Timestamps are UTC ISO-8601 strings: LogEvent.timestamp is a time-only
     * "HH:MM:SS" in the user's system timezone, so we combine it with the
     * file's logged_at date and convert to UTC via ConvertMtgoTimestamp.
     *
     * @return array<int, array{timestamp: string, message: string}>
     */
    public static function run(string $matchToken): array
    {
        return LogEvent::query()
            ->where('match_token', $matchToken)
            ->where('event_type', 'game_management_json')
            ->orderBy('timestamp')
            ->orderBy('byte_offset_start')
            ->orderBy('id')
            ->cursor()
            ->map(function (LogEvent $event) {
                $json = ExtractJson::run($event->raw_text)->first();
                if (! is_array($json)) {
                    return null;
                }

                $bytes = $json['MetaMessage'] ?? null;
                if (! is_array($bytes)) {
                    return null;
                }

                $message = DecodeMetaMessageText::run($bytes);
                if ($message === null) {
                    return null;
                }

                return [
                    'timestamp' => ConvertMtgoTimestamp::run($event->logged_at, $event->timestamp)->toIso8601String(),
                    'message' => $message,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
