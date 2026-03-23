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
            static::resolveForMatch($match);
        }
    }

    private static function resolveForMatch(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();
        if (! $gameLog || empty($gameLog->decoded_entries)) {
            return;
        }

        $username = Mtgo::getUsername();
        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($gameLog->decoded_entries, $username);

        // Progressive: update Game.won for each game
        static::syncGameResults($match, $extracted['results'] ?? []);

        // Only determine final outcome for Ended matches
        if ($match->state !== MatchState::Ended) {
            return;
        }

        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'] ?? [])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            logResults: $extracted['results'] ?? [],
            stateChanges: $stateChanges,
            matchScoreExists: ! empty($extracted['match_score']),
            disconnectDetected: $disconnectDetected,
        );

        if ($result['decided']) {
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);
            $match->update([
                'outcome' => $outcome,
                'state' => MatchState::Complete,
            ]);
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
            ]);

            return;
        }

        // Grace period: 2 minutes past ended_at
        if ($match->ended_at && $match->ended_at->lt(now()->subMinutes(2))) {
            $match->update(['state' => MatchState::PendingResult]);
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: Ended → PendingResult");
        }
    }

    private static function syncGameResults(MtgoMatch $match, array $results): void
    {
        $games = $match->games()->orderBy('started_at')->get();
        foreach ($games as $index => $game) {
            if (! isset($results[$index])) {
                continue;
            }
            if ($game->won === null || (bool) $game->won !== $results[$index]) {
                $game->update(['won' => $results[$index]]);
            }
        }
    }
}
