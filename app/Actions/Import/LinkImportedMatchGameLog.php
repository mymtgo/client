<?php

namespace App\Actions\Import;

use App\Models\GameLog;
use App\Models\MtgoMatch;
use App\Models\Player;

class LinkImportedMatchGameLog
{
    /**
     * Seconds either side of the match start that a game log may begin.
     */
    private const WINDOW_SECONDS = 300;

    /**
     * Re-key an imported match's orphan .dat GameLog to the match token.
     *
     * Imports assign a random match token unrelated to the .dat game-log token,
     * so the decoded log stays orphaned and the card-stats pipeline
     * (EnsureGameLogForMatch / ComputeCardGameStats) can never find it. Match the
     * log to the history record by opponent username and start time — the same
     * StartTime ± 5 minutes + opponent heuristic the importer uses — then point
     * the log's match_token at the match so the rest of the pipeline resolves it.
     */
    public static function run(MtgoMatch $match): ?GameLog
    {
        $existing = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! $match->started_at) {
            return null;
        }

        $opponentName = self::opponentName($match);

        if (! $opponentName) {
            return null;
        }

        $lower = $match->started_at->copy()->subSeconds(self::WINDOW_SECONDS);
        $upper = $match->started_at->copy()->addSeconds(self::WINDOW_SECONDS);

        $log = GameLog::query()
            ->whereDoesntHave('match')
            ->whereNotNull('decoded_entries')
            ->whereNotNull('first_timestamp')
            ->whereBetween('first_timestamp', [$lower, $upper])
            ->get()
            ->filter(fn (GameLog $candidate): bool => in_array($opponentName, $candidate->players ?? [], true))
            ->sortBy(fn (GameLog $candidate): int => abs($candidate->first_timestamp->diffInSeconds($match->started_at)))
            ->first();

        if (! $log) {
            return null;
        }

        $log->update(['match_token' => $match->token]);

        return $log;
    }

    private static function opponentName(MtgoMatch $match): ?string
    {
        return Player::query()
            ->join('game_player', 'players.id', '=', 'game_player.player_id')
            ->join('games', 'games.id', '=', 'game_player.game_id')
            ->where('games.match_id', $match->id)
            ->where('game_player.is_local', false)
            ->value('players.username');
    }
}
