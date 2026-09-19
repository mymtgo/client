<?php

namespace App\Dashboard;

/**
 * The layout a fresh install shows: today's dashboard, card for card.
 * Ids are fixed so a fresh install is deterministic.
 */
class DefaultLayout
{
    /** @return array<int, array{id: string, type: string, config: array<string, mixed>}> */
    public static function instances(): array
    {
        return [
            ['id' => 'default-kpi-strip', 'type' => 'kpi_strip', 'config' => []],
            ['id' => 'default-league-results', 'type' => 'league_results', 'config' => []],
            ['id' => 'default-rolling-form', 'type' => 'rolling_form', 'config' => []],
            ['id' => 'default-last-session', 'type' => 'last_session', 'config' => []],
            ['id' => 'default-deck-performance', 'type' => 'deck_performance', 'config' => []],
            ['id' => 'default-matchup-spread', 'type' => 'matchup_spread', 'config' => []],
            ['id' => 'default-recent-matches', 'type' => 'recent_matches', 'config' => []],
        ];
    }
}
