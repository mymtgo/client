<?php

namespace App\Updates;

use App\Actions\RegenerateCardGameStats;

class RegenerateCardStatsWithCastingMethods extends AppUpdate
{
    /**
     * Backfill the casting-method counters (warp, free_cast, dash, ...) added
     * in the 2026-08-18 card_game_stats migration, and re-attribute stats for
     * players whose usernames contain spaces (invisible to the old
     * PLAYER_PATTERN) and casts logged under multi-face face CatalogIDs.
     * Recomputes every complete match from its durable decoded game log.
     */
    public function run(): void
    {
        RegenerateCardGameStats::run();
    }
}
