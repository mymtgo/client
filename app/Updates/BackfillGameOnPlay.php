<?php

namespace App\Updates;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\SyncGamePivots;
use App\Facades\Mtgo;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

/**
 * Re-derive on_play, won, and ended_at for historical games.
 *
 * Pre-fix CreateGames could record on_play=false for both players when the
 * binary game log was empty during early ingestion, and the index-mismatched
 * flat `on_play` array silently fell off the end of multi-game matches.
 * This update walks every match's stored decoded_entries and applies the
 * canonical per-game data via SyncGamePivots.
 */
class BackfillGameOnPlay extends AppUpdate
{
    public function run(): void
    {
        $updated = 0;

        MtgoMatch::query()
            ->whereHas('games')
            ->with(['games.players'])
            ->lazy()
            ->each(function (MtgoMatch $match) use (&$updated) {
                if ($this->backfillMatch($match)) {
                    $updated++;
                }
            });

        Log::info("BackfillGameOnPlay: processed {$updated} matches");
    }

    private function backfillMatch(MtgoMatch $match): bool
    {
        $gameLog = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        if (! $gameLog) {
            return false;
        }

        try {
            $players = ExtractGameResults::detectPlayers($gameLog->decoded_entries);
            $username = Mtgo::resolveUsername($players);

            if (! $username) {
                return false;
            }

            $extracted = ExtractGameResults::run($gameLog->decoded_entries, $username);
            $games = $match->games->sortBy('started_at')->values();

            foreach ($games as $index => $game) {
                SyncGamePivots::forGame($game, $extracted['games'][$index] ?? null, $username);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("BackfillGameOnPlay: failed match {$match->id}: {$e->getMessage()}");

            return false;
        }
    }
}
