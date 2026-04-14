<?php

namespace App\Actions;

use App\Actions\Import\ComputeImportedCardGameStats;
use App\Actions\Import\ExtractCardsFromGameLog;
use App\Jobs\ComputeCardGameStats;
use App\Models\CardGameStat;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class RegenerateCardGameStats
{
    /**
     * Truncate card_game_stats and recompute for all complete matches.
     *
     * @return array{live: int, imported: int}
     */
    public static function run(): array
    {
        // Truncate resets auto-increment IDs back to 1
        CardGameStat::truncate();

        $live = 0;
        $imported = 0;

        // Live matches — dispatch as jobs (they need timeline data loaded)
        $liveMatchIds = MtgoMatch::query()
            ->where('imported', false)
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->whereHas('games')
            ->pluck('id');

        foreach ($liveMatchIds as $matchId) {
            ComputeCardGameStats::dispatch($matchId)->onQueue('default');
            $live++;
        }

        // Imported matches — process inline (no timeline, simpler)
        $importedMatches = MtgoMatch::query()
            ->where('imported', true)
            ->whereNotNull('deck_version_id')
            ->whereHas('games')
            ->with(['games.players'])
            ->get();

        foreach ($importedMatches as $match) {
            try {
                self::reprocessImportedMatch($match);
                $imported++;
            } catch (\Throwable $e) {
                Log::warning("RegenerateCardGameStats: failed imported match {$match->id}: {$e->getMessage()}");
            }
        }

        Log::info("RegenerateCardGameStats: truncated + queued {$live} live, processed {$imported} imported");

        return ['live' => $live, 'imported' => $imported];
    }

    private static function reprocessImportedMatch(MtgoMatch $match): void
    {
        $gameLog = GameLog::where('match_token', $match->token)
            ->whereNotNull('decoded_entries')
            ->first();

        if (! $gameLog) {
            return;
        }

        $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
        $cardsByGame = $cardData['cards_by_game'] ?? [];
        $gameMeta = $cardData['game_meta'] ?? [];

        $firstGame = $match->games->sortBy('started_at')->first();
        if (! $firstGame) {
            return;
        }

        $localPlayer = $firstGame->players->first(fn ($p) => $p->pivot->is_local);
        if (! $localPlayer) {
            return;
        }

        $localName = $localPlayer->username;

        foreach ($match->games->sortBy('started_at')->values() as $index => $game) {
            if ($game->won === null || ! $match->deck_version_id) {
                continue;
            }

            $gameCards = $cardsByGame[$index][$localName] ?? [];

            ComputeImportedCardGameStats::run(
                $game,
                $match->deck_version_id,
                $gameCards,
                isPostboard: $index > 0,
            );

            // Write game metadata
            $meta = $gameMeta[$index] ?? [];
            if (! empty($meta['turn_count'])) {
                $game->update(['turn_count' => $meta['turn_count']]);
            }
        }
    }
}
