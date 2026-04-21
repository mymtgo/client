<?php

namespace App\Actions\Tournaments;

use App\Actions\Util\ExtractJson;
use App\Enums\LogEventType;
use App\Models\LogEvent;

class ExtractTournamentPayload
{
    /**
     * Return a structured payload for an already-classified tournament log event.
     * For JSON-carrying events, returns the extracted JSON object. For state
     * changes, returns a minimal synthesised payload. Returns [] if extraction fails.
     *
     * @return array<string, mixed>
     */
    public static function run(LogEvent $event): array
    {
        return match ($event->event_type) {
            LogEventType::TOURNAMENT_SYNC->value,
            LogEventType::TOURNAMENT_ROUND_RESULT->value,
            LogEventType::TOURNAMENT_ROUND_INFO->value,
            LogEventType::TOURNAMENT_PLAYER_ELIMINATED->value,
            LogEventType::TOURNAMENT_ENDED->value => self::fromJson($event),

            LogEventType::TOURNAMENT_STATE_CHANGED->value => self::fromTournamentStateChange($event),

            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function fromJson(LogEvent $event): array
    {
        $json = ExtractJson::run($event->raw_text)->first();

        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    private static function fromTournamentStateChange(LogEvent $event): array
    {
        if (preg_match(
            '/Tournament State Changed for [a-f0-9\-]{36} from (?<from>\S+) to (?<to>\S+?)\)?$/i',
            trim($event->raw_text),
            $m,
        )) {
            return [
                'from' => $m['from'],
                'to' => $m['to'],
            ];
        }

        return [];
    }
}
