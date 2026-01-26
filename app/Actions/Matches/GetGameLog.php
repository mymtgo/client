<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\GameLog;

class GetGameLog
{
    public static function run(string $token): ?array
    {
        $log = GameLog::where('match_token', $token)->first();

        if (! $log) {
            return null;
        }

        $raw = file_get_contents($log->file_path);

        /**
         * Clean log
         */
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $raw);
        $clean = str_replace('@P', "\n@P", $clean);
        $clean = preg_replace('/[ \t]+/', ' ', $clean);

        // ✅ single source of truth
        $you = Mtgo::getUsername();

        if (! $you) {
            throw new \RuntimeException('MTGO username not set');
        }

        /**
         * ===============================
         * TURN ORDER
         * ===============================
         */
        preg_match_all(
            '/@P(?<player>[A-Za-z0-9_]+) chooses to play (first|second)/',
            $clean,
            $turnOrder,
            PREG_SET_ORDER
        );

        /**
         * ===============================
         * MULLIGANS / STARTING HANDS
         * ===============================
         */
        preg_match_all(
            '/@P(?<player>[A-Za-z0-9_]+)\s+.*?\bbegins the game with\s+(?<hand>(?:\d+|one|two|three|four|five|six|seven))\s+cards?\s+in\s+hand\b/i',
            $clean,
            $mulligans,
            PREG_SET_ORDER
        );

        /**
         * ===============================
         * GAME END EVENTS
         * ===============================
         */

        // Explicit wins
        preg_match_all(
            '/@P(?<player>[A-Za-z0-9_]+)\s+wins the game\b/i',
            $clean,
            $winMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        // Terminal losses (concede / disconnect)
        preg_match_all(
            '/@P(?<player>[A-Za-z0-9_]+)\s+has\s+(?<reason>conceded from the game|lost connection to the game)\b/i',
            $clean,
            $terminalMatches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        /**
         * Merge + sort events by file position
         */
        $events = [];

        foreach ($winMatches as $m) {
            $events[] = [
                'type' => 'win',
                'player' => $m['player'][0],
                'offset' => $m[0][1],
            ];
        }

        foreach ($terminalMatches as $m) {
            $events[] = [
                'type' => 'terminal',
                'player' => $m['player'][0], // loser
                'offset' => $m[0][1],
                'reason' => strtolower($m['reason'][0]),
            ];
        }

        usort($events, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        /**
         * Build game results
         */
        $gameResults = [];
        $pendingLoser = null;

        foreach ($events as $e) {

            if ($e['type'] === 'terminal') {
                // remember loser in case MTGO never prints a win line
                $pendingLoser = $e['player'];

                continue;
            }

            if ($e['type'] === 'win') {
                $winner = $e['player'];

                $pendingLoser = null;

                $gameResults[] = ($winner === $you);
            }
        }

        /**
         * Fallback:
         * terminal event but no explicit "wins the game"
         */
        if ($pendingLoser !== null && count($gameResults) < 3) {
            $gameResults[] = ($pendingLoser !== $you);
        }

        /**
         * ===============================
         * ON PLAY / DRAW
         * ===============================
         */
        $onPlay = [];

        foreach ($turnOrder as $turn) {
            $isYou = $turn['player'] === $you;
            $onPlay[] = $isYou && str_contains($turn[0], 'first');
        }

        /**
         * ===============================
         * NORMALISE STARTING HANDS
         * ===============================
         */
        $map = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
            'five' => 5, 'six' => 6, 'seven' => 7,
        ];

        $starts = collect($mulligans)->map(function ($m) use ($map) {
            $handRaw = strtolower($m['hand']);
            $hand = ctype_digit($handRaw)
                ? (int) $handRaw
                : ($map[$handRaw] ?? null);

            return [
                'player' => $m['player'],
                'starting_hand' => $hand,
            ];
        })->values();

        return [
            'results' => $gameResults,
            'on_play' => $onPlay,
            'starting_hands' => $starts->toArray(),
        ];
    }
}
