<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\DetermineMatchResult;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Game;
use App\Models\GameLog;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

        // Parse fresh every tick
        $raw = file_get_contents($gameLog->file_path);

        if ($raw === false || $raw === '') {
            return;
        }

        $decoded = ParseGameLogBinary::run($raw);

        if (empty($decoded) || empty($decoded['entries'])) {
            return;
        }

        $entries = $decoded['entries'];

        // Persist decoded entries so CreateGames and GetGameLogEntries can use them
        $gameLog->update(['decoded_entries' => $entries]);

        $players = ExtractGameResults::detectPlayers($entries);
        $username = Mtgo::resolveUsername($players);

        if (! $username) {
            return;
        }

        $extracted = ExtractGameResults::run($entries, $username);

        // Sync game results progressively
        self::syncGameResults($match, $extracted['results'], $extracted['games'], $username);

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
     * Sync individual game win/loss results, ended_at timestamps, and on_play pivots.
     *
     * @param  array<int, bool>  $results
     * @param  array<int, array<string, mixed>>  $gameData
     */
    private static function syncGameResults(MtgoMatch $match, array $results, array $gameData, string $username): void
    {
        $games = $match->games()->with('players')->orderBy('started_at')->get();

        foreach ($games as $index => $game) {
            $updates = [];

            if (isset($results[$index])
                && ($game->won === null || (bool) $game->won !== $results[$index])
            ) {
                $updates['won'] = $results[$index];
            }

            if ($game->ended_at === null && ! empty($gameData[$index]['ended_at'])) {
                $updates['ended_at'] = Carbon::parse($gameData[$index]['ended_at']);
            }

            if (! empty($updates)) {
                $game->update($updates);
            }

            // CreateGames may set on_play=false during early ingestion when the
            // binary game log file is empty. Re-derive on_play once the log
            // contains the "chooses to play" line for this game.
            $onPlayName = $gameData[$index]['on_play'] ?? null;

            if ($onPlayName !== null) {
                self::syncOnPlay($game, $username, $onPlayName);
            }
        }
    }

    /**
     * Update the on_play pivot for both players of a game based on the
     * authoritative player name parsed from the game log.
     */
    private static function syncOnPlay(Game $game, string $username, string $onPlayName): void
    {
        $localPlayer = $game->players->first(fn ($p) => $p->username === $username);
        $opponent = $game->players->first(fn ($p) => $p->username !== $username);

        if (! $localPlayer || ! $opponent) {
            return;
        }

        $localOnPlay = $onPlayName === $username;
        $opponentOnPlay = $onPlayName === $opponent->username;

        if ((bool) $localPlayer->pivot->on_play !== $localOnPlay) {
            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('player_id', $localPlayer->id)
                ->update(['on_play' => $localOnPlay]);
        }

        if ((bool) $opponent->pivot->on_play !== $opponentOnPlay) {
            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('player_id', $opponent->id)
                ->update(['on_play' => $opponentOnPlay]);
        }
    }
}
