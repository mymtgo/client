<?php

namespace App\Actions\Logs;

use App\Actions\Util\ExtractJson;
use App\Enums\LogEventType;
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

        // Tournament events. Must come BEFORE game_management_json because the
        // tournament sync payload contains a nested MatchCreateInfo.MatchToken
        // that would falsely match the generic JSON branch.

        // tournament_state_changed — no JSON, UUID is embedded in context.
        // Real MTGO format: "Tournament State Changed for <UUID> from X to Y"
        if (preg_match('/Tournament State Changed for (?<token>[a-f0-9\-]{36}) from \S+ to \S+/i', $text, $m)) {
            return $event->fill([
                'event_type' => LogEventType::TOURNAMENT_STATE_CHANGED->value,
                'tournament_token' => $m['token'],
            ]);
        }

        // JSON-carrying tournament events. Marker => [event type, json key for token].
        $tournamentJsonMarkers = [
            'EventSyncData_t' => [LogEventType::TOURNAMENT_SYNC, 'EventToken'],
            'FlsTournamentRoundInfoMessage' => [LogEventType::TOURNAMENT_ROUND_INFO, 'Token'],
            'FlsTournamentRoundResultMessage' => [LogEventType::TOURNAMENT_ROUND_RESULT, 'Token'],
            'FlsTournamentPlayerIsEliminatedMessage' => [LogEventType::TOURNAMENT_PLAYER_ELIMINATED, 'Token'],
            'FlsTournamentEndRespMessage' => [LogEventType::TOURNAMENT_ENDED, 'Token'],
        ];

        foreach ($tournamentJsonMarkers as $marker => [$type, $tokenKey]) {
            if (! str_contains($text, $marker)) {
                continue;
            }

            // Extract the token via regex rather than json_decode — MTGO
            // occasionally ships malformed/truncated JSON (e.g. FlsTournamentRoundInfoMessage
            // with a missing outer closing brace). A direct regex on the key
            // survives those while ExtractJson would fall through to an inner block.
            $token = null;
            $pattern = '/"'.preg_quote($tokenKey, '/').'"\s*:\s*"(?<token>[a-f0-9\-]{36})"/i';
            if (preg_match($pattern, $text, $m)) {
                $token = $m['token'];
            }

            if ($token === null) {
                Log::warning('ClassifyLogEvent: tournament marker matched but token missing', [
                    'marker' => $marker,
                    'token_key' => $tokenKey,
                    'text_preview' => mb_substr($text, 0, 200),
                ]);
            }

            return $event->fill([
                'event_type' => $type->value,
                'tournament_token' => $token,
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

        // League view — fired when MTGO displays the league details panel.
        // Two variants: "Creating GameDetailsView) League" (first join) and
        // "Join Event) League" (re-entry). Both carry EventToken and EventId.
        // ProcessLeagueEvents correlates with a preceding league_join_request.
        if (preg_match('/(?:Creating GameDetailsView|Join Event)\) League\b/', $text)) {
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
