<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
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

        if ($result['decided']) {
            $previousState = $match->state;
            $outcome = MtgoMatch::determineOutcome($result['wins'], $result['losses']);

            $endedAt = $match->ended_at;
            if ($match->state === MatchState::InProgress) {
                $lastTs = end($entries)['timestamp'] ?? null;
                $endedAt = $lastTs
                    ? Carbon::parse($lastTs)
                    : now();
            }

            $match->update([
                'outcome' => $outcome,
                'state' => MatchState::Complete,
                'ended_at' => $endedAt,
            ]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: {$previousState->value} → Complete", [
                'result' => "{$result['wins']}-{$result['losses']}",
                'outcome' => $outcome->value,
                'source' => 'game_log',
            ]);
        }
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
