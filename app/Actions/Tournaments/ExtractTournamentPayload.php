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

            LogEventType::TOURNAMENT_MATCH_STATE_CHANGED->value => self::fromMatchStateChange($event),

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
        if (preg_match('/Tournament State Changed from (\S+) to (\S+)/', $event->raw_text, $m)) {
            return [
                'from' => $m[1],
                'to' => $m[2],
            ];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private static function fromMatchStateChange(LogEvent $event): array
    {
        if (preg_match('/TournamentMatch State Changed for (?<token>[a-f0-9\-]{32,36}) from (?<from>\S+) to (?<to>\S+)/i', $event->raw_text, $m)) {
            return [
                'match_token' => $m['token'],
                'from' => $m['from'],
                'to' => $m['to'],
            ];
        }

        return [];
    }
}
