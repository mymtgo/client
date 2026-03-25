<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveGameResults
{
    /**
     * Parse game log for a match and resolve results if decisive.
     */
    public static function run(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)->first();

        if (! $gameLog) {
            $gameLog = DiscoverGameLogs::discoverForToken($match->token);
        }

        if (! $gameLog || ! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        // Parse fresh every tick
        $raw = file_get_contents($gameLog->file_path);

        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = ParseGameLogBinary::run($raw);

        if (empty($decoded)) {
            return;
        }

        $players = ExtractGameResults::detectPlayers($decoded);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($decoded, $username);

        // Sync game results progressively
        static::syncGameResults($match, $extracted['results'], $extracted['games']);

        // Check if decisive
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
        }
    }

    /**
     * Sync individual game win/loss results and ended_at timestamps.
     *
     * @param  array<int, bool>  $results
     * @param  array<int, array<string, mixed>>  $gameData
     */
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
