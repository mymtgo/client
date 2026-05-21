<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Actions\Matches\ParseMatchHistory;
use App\Actions\Matches\SyncGamePivots;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ResolveGameResults
{
    /**
     * Parse game log for a match and resolve results if decisive.
     */
    public static function run(MtgoMatch $match): void
    {
        if (! $match->hasValidPlayers()) {
            return;
        }

        $gameLog = GameLog::where('match_token', $match->token)->first();

        if (! $gameLog) {
            $gameLog = DiscoverGameLogs::discoverForToken($match->token);
        }

        if (! $gameLog || ! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        $raw = file_get_contents($gameLog->file_path);

        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = ParseGameLogBinary::run($raw);

        if (empty($decoded) || empty($decoded['entries'])) {
            return;
        }

        $entries = $decoded['entries'];
        $gameLog->update(['decoded_entries' => $entries]);

        $players = ExtractGameResults::detectPlayers($entries);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($entries, $username);

        self::syncGames($match, $extracted['games'], $username);

        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        $disconnectDetected = collect($extracted['games'])
            ->contains(fn ($g) => ($g['end_reason'] ?? '') === 'disconnect');

        $result = DetermineMatchResult::run(
            games: $extracted['games'],
            localPlayer: $username,
            stateChanges: $stateChanges,
            matchScore: $extracted['match_score'],
            matchScoreExists: $extracted['match_decided'],
            disconnectDetected: $disconnectDetected,
        );

        if (! $result['decided']) {
            return;
        }

        $wins = $result['wins'];
        $losses = $result['losses'];
        $source = 'game_log';

        // Server says the match ended but our per-game count is partial
        // (game-end events lag behind the MatchCompletedState event, and
        // the final game's .dat file may not be flushed yet). Consult
        // mtgo_game_history, which is authoritative once MTGO has written
        // it. If history is still a 0-0 placeholder, hold off — the next
        // tick (or ReconcileStuckMatches) will pick it up.
        if (! $result['authoritative']) {
            $historyResult = ParseMatchHistory::findResult($match->mtgo_id);

            if ($historyResult === null) {
                Log::channel('pipeline')->info("Match {$match->mtgo_id}: server-completed but history not ready — deferring", [
                    'game_log_count' => "{$wins}-{$losses}",
                ]);

                return;
            }

            $wins = $historyResult['wins'];
            $losses = $historyResult['losses'];
            $source = 'match_history';
        }

        $previousState = $match->state;
        $outcome = MtgoMatch::determineOutcome($wins, $losses);

        $endedAt = $match->ended_at;
        if ($match->state === MatchState::InProgress) {
            $lastTs = end($entries)['timestamp'] ?? null;
            $endedAt = $lastTs
                ? Carbon::parse($lastTs)
                : now();
        }

        $match->update([
            'outcome' => $outcome,
            'games_won' => $wins,
            'games_lost' => $losses,
            'state' => MatchState::Complete,
            'ended_at' => $endedAt,
        ]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
            'result' => "{$wins}-{$losses}",
            'outcome' => $outcome->value,
            'source' => $source,
        ]);
    }

    /**
     * Sync per-game results onto each Game model in match-order.
     *
     * @param  array<int, array<string, mixed>>  $games
     */
    private static function syncGames(MtgoMatch $match, array $games, string $username): void
    {
        $persistedGames = $match->games()->with('players')->orderBy('started_at')->get();

        foreach ($persistedGames as $index => $game) {
            SyncGamePivots::forGame($game, $games[$index] ?? null, $username);
        }
    }
}
