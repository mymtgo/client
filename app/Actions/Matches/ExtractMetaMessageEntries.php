<?php

namespace App\Actions\Matches;

use App\Actions\Util\ExtractJson;
use App\Models\LogEvent;
use Illuminate\Support\Carbon;

class ExtractMetaMessageEntries
{
    /**
     * Build the {timestamp, message}[] entries shape for a match from its
     * MetaMessage log events. Same shape ExtractGameResults consumes.
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
                    'timestamp' => Carbon::parse($event->timestamp)->toIso8601String(),
                    'message' => $message,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
