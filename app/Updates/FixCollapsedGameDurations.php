<?php

namespace App\Updates;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\RepairGameTimestampsFromEntries;
use App\Models\GameLog;
use App\Models\MtgoMatch;

/**
 * Repair games whose ended_at was frozen moments after they began.
 *
 * SyncGamePivots used to write ended_at once, and a game is projected on every
 * pipeline tick while it is still being played — so the value that stuck was
 * the one from the first pass, a second or two in. Those games show a 0s
 * duration, and their game log view shows only the opening lines because it
 * filters entries to the game's own time window.
 *
 * The log events behind them are pruned once a match completes, but the decoded
 * game log survives, so the real per-game times can be recovered from there.
 */
class FixCollapsedGameDurations extends AppUpdate
{
    /**
     * Below this, a recorded game is a projection artefact rather than a
     * genuinely quick game — a game that fast still takes a few concessions'
     * worth of clicks.
     */
    private const COLLAPSED_SECONDS = 30;

    public function run(): void
    {
        MtgoMatch::query()
            ->whereHas('games', fn ($games) => $games
                ->whereNotNull('started_at')
                ->whereNotNull('ended_at')
                // Interpolated, not bound: SQLite binds the number as TEXT,
                // which always sorts above REAL, so the comparison never matches.
                ->whereRaw('(julianday(ended_at) - julianday(started_at)) * 86400 < '.self::COLLAPSED_SECONDS)
            )
            ->each(function (MtgoMatch $match) {
                $entries = GameLog::query()
                    ->where('match_token', $match->token)
                    ->value('decoded_entries');

                if (empty($entries)) {
                    return;
                }

                // Games are paired with entry groups by position. When the two
                // disagree the pairing is a guess, and a wrong guess would move
                // times that are currently right, so leave the match alone.
                if (count(ExtractGameResults::splitIntoGames($entries)) !== $match->games()->count()) {
                    return;
                }

                RepairGameTimestampsFromEntries::run($match, $entries);
            });
    }
}
