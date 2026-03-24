<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveGameResults
{
    public static function run(): void
    {
        $matches = MtgoMatch::whereIn('state', [
            MatchState::InProgress,
            MatchState::Ended,
        ])->get();

        foreach ($matches as $match) {
            self::resolveForMatch($match);
        }
    }

    private static function resolveForMatch(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();
        if (! $gameLog || empty($gameLog->decoded_entries)) {
            return;
        }

        $players = ExtractGameResults::detectPlayers($gameLog->decoded_entries);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            Log::channel('pipeline')->warning("ResolveGameResults: skipping match {$match->mtgo_id} — username unavailable (candidates: ".implode(', ', $players).')');

            return;
        }

        $extracted = ExtractGameResults::run($gameLog->decoded_entries, $username);

        // Progressive: update Game.won and ended_at for each game
        self::syncGameResults($match, $extracted['results'], $extracted['games']);

        // Determine if the game log provides a decisive result.
        // The game log binary is MTGO's authoritative record of results —
        // it can drive match completion independently of main log state signals.
        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            logResults: $extracted['results'],
            stateChanges: $stateChanges,
            matchScoreExists: $extracted['match_decided'],
            disconnectDetected: $disconnectDetected,
        );

        if ($result['decided']) {
            $previousState = $match->state;
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $match->update([
                'outcome' => $outcome,
                'state' => MatchState::Complete,
                'ended_at' => $match->state === MatchState::InProgress
                    ? now()
                    : $match->ended_at,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
                'source' => 'game_log',
            ]);

            return;
        }

        // Grace period: only for matches that reached Ended via main log signals
        if ($match->state === MatchState::Ended && $match->ended_at?->lt(now()->subMinutes(2))) {
            $match->update(['state' => MatchState::PendingResult]);
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → PendingResult");
        }
    }

    private static function syncGameResults(MtgoMatch $match, array $results, array $gameData): void
    {
        $games = $match->games()->orderBy('started_at')->get();
        foreach ($games as $index => $game) {
            if (! isset($results[$index])) {
                continue;
            }

            $updates = [];

            if ($game->won === null || (bool) $game->won !== $results[$index]) {
                $updates['won'] = $results[$index];
            }

            if ($game->ended_at === null && ! empty($gameData[$index]['ended_at'])) {
                $updates['ended_at'] = $gameData[$index]['ended_at'];
            }

            if (! empty($updates)) {
                $game->update($updates);
            }
        }
    }
}
