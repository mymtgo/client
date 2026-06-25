<?php

namespace App\Actions\Archetypes;

use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\MatchArchetype;

class GetArchetypeVariantFacingWinrates
{
    /**
     * @return array<int, array{winrate:int, wins:int, losses:int}> keyed by archetype_deck_id
     */
    public static function run(Archetype $archetype): array
    {
        $rows = MatchArchetype::query()
            ->from('match_archetypes as ma')
            ->join('matches as m', 'm.id', '=', 'ma.mtgo_match_id')
            ->where('ma.archetype_id', $archetype->id)
            ->whereNotNull('ma.archetype_deck_id')
            ->where('m.state', MatchState::Complete->value)
            ->where('ma.is_opponent', true)
            ->groupBy('ma.archetype_deck_id')
            ->selectRaw("
                ma.archetype_deck_id as deck_id,
                SUM(CASE WHEN m.outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN m.outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $wins = (int) $row->wins;
            $losses = (int) $row->losses;
            $total = $wins + $losses;

            if ($total === 0) {
                continue;
            }

            $result[(int) $row->deck_id] = [
                'winrate' => (int) round(100 * $wins / $total),
                'wins' => $wins,
                'losses' => $losses,
            ];
        }

        return $result;
    }
}
