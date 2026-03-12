<?php

namespace App\Actions\Archetypes;

use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\MatchArchetype;

class GetArchetypeWinrates
{
    /**
     * @return array{playing: ?array{winrate: int, wins: int, losses: int}, facing: ?array{winrate: int, wins: int, losses: int}}
     */
    public static function run(Archetype $archetype): array
    {
        return [
            'playing' => self::calculate($archetype, isLocal: true),
            'facing' => self::calculate($archetype, isLocal: false),
        ];
    }

    private static function calculate(Archetype $archetype, bool $isLocal): ?array
    {
        $result = MatchArchetype::query()
            ->from('match_archetypes as ma')
            ->join('matches as m', 'm.id', '=', 'ma.mtgo_match_id')
            ->where('ma.archetype_id', $archetype->id)
            ->where('m.state', MatchState::Complete->value)
            ->whereExists(function ($q) use ($isLocal) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', $isLocal);
            })
            ->selectRaw('
                SUM(CASE WHEN m.games_won > m.games_lost THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN m.games_won < m.games_lost THEN 1 ELSE 0 END) as losses,
                COUNT(*) as total
            ')
            ->first();

        if (! $result || $result->total === 0) {
            return null;
        }

        $wins = (int) $result->wins;
        $losses = (int) $result->losses;
        $total = $wins + $losses;

        return [
            'winrate' => $total > 0 ? (int) round(100 * $wins / $total) : 0,
            'wins' => $wins,
            'losses' => $losses,
        ];
    }
}
