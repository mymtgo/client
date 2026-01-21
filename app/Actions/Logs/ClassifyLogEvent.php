<?php

namespace App\Actions\Logs;

use App\Actions\Util\ExtractJson;
use App\Models\LogEvent;

class ClassifyLogEvent
{
    public static function run(LogEvent $event): LogEvent
    {
        $text = $event->raw_text;

        // Match state change
        if (preg_match('/Match State Changed for (?<token>[a-f0-9\-]+)/i', $text, $m)) {
            return $event->fill([
                'event_type' => 'match_state_changed',
                'match_token' => $m['token'],
            ]);
        }

        // Game state update
        if (preg_match('/Game ID:\s*(?<game>\d+), Match ID:\s*(?<match>\d+)/', $text, $m)) {
            return $event->fill([
                'event_type' => 'game_state_update',
                'game_id' => (int) $m['game'],
                'match_id' => (int) $m['match'],
            ]);
        }

        // Deck used
        if (preg_match('/Deck Used in Game ID:\s*(?<game>\d+)/', $text, $m)) {
            return $event->fill([
                'event_type' => 'deck_used',
                'game_id' => (int) $m['game'],
            ]);
        }

        if (str_contains($text, 'Message:') && (str_contains($text, '{"MatchToken"') || str_contains($text, '{"MatchID"'))) {
            $json = ExtractJson::run($text)->first();

            if (is_array($json)) {
                return $event->fill([
                    'event_type' => 'game_management_json',
                    'match_token' => $json['MatchToken'] ?? null,
                    'match_id' => isset($json['MatchID']) ? (int) $json['MatchID'] : null,
                    'game_id' => isset($json['GameID']) ? (int) $json['GameID'] : null,
                ]);
            }
        }

        return $event;
    }
}
