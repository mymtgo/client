<?php

use App\Actions\Matches\GetGameLog;
use App\Actions\Matches\SyncGameResults;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Re-sync game `won` fields from log files for completed matches
     * where game outcomes are inconsistent with the match result.
     */
    public function up(): void
    {
        $inconsistent = DB::table('matches')
            ->join('games', 'matches.id', '=', 'games.match_id')
            ->where('matches.state', MatchState::Complete->value)
            ->whereNotNull('matches.games_won')
            ->whereNull('matches.deleted_at')
            ->select(
                'matches.id',
                'matches.token',
                'matches.games_won',
                'matches.games_lost',
                DB::raw('SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as actual_won'),
                DB::raw('SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as actual_lost'),
            )
            ->groupBy('matches.id', 'matches.token', 'matches.games_won', 'matches.games_lost')
            ->havingRaw('matches.games_won != actual_won OR matches.games_lost != actual_lost')
            ->get();

        if ($inconsistent->isEmpty()) {
            return;
        }

        Log::info("Found {$inconsistent->count()} matches with inconsistent game results");

        foreach ($inconsistent as $row) {
            $match = MtgoMatch::find($row->id);

            if (! $match) {
                continue;
            }

            $gameLog = GetGameLog::run($match->token);

            if ($gameLog && ! empty($gameLog['results'])) {
                SyncGameResults::run($match, $gameLog['results']);
                Log::info("Synced game results for match {$match->id} from log file");
            } else {
                Log::warning("No game log available for match {$match->id}, skipping");
            }
        }
    }

    /**
     * Not reversible — original values are not stored.
     */
    public function down(): void {}
};
