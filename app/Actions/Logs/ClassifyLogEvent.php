<?php

namespace App\Actions\Logs;

use App\Actions\Util\ExtractJson;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

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

            Log::warning('ClassifyLogEvent: matched JSON pattern but extraction failed', [
                'text_preview' => mb_substr($text, 0, 200),
            ]);
        }

        // League join request — the authoritative signal that the user clicked "Join"
        if (str_contains($text, 'FlsLeagueUserJoinReqMessage')) {
            return $event->fill([
                'event_type' => 'league_join_request',
            ]);
        }

        // League view — "(UI|Creating GameDetailsView) League" with EventToken and EventId.
        // This fires on every league view (not just joins), so ProcessLeagueEvents
        // correlates it with a preceding league_join_request to confirm a real join.
        if (str_contains($text, 'Creating GameDetailsView') && preg_match('/Creating GameDetailsView\) League\b/', $text)) {
            $eventToken = null;
            $eventId = null;

            if (preg_match('/EventToken=(\S+)/', $text, $m)) {
                $eventToken = $m[1];
            }
            if (preg_match('/EventId=(\d+)/', $text, $m)) {
                $eventId = $m[1];
            }

            if ($eventToken && $eventId) {
                return $event->fill([
                    'event_type' => 'league_joined',
                    'match_token' => $eventToken,
                    'match_id' => $eventId,
                ]);
            }
        }

        return $event;
    }
}
