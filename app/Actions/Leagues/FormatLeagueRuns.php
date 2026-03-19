<?php

namespace App\Actions\Leagues;

use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FormatLeagueRuns
{
    /**
     * Format a collection of leagues into display-ready run data.
     *
     * @param  Collection<int, League>  $leagues  Leagues with deckVersion.deck eager loaded
     * @param  int|null  $accountId  Filter matches by account (null = all)
     * @param  int|null  $deckId  Filter matches to a specific deck (null = all)
     * @return array<int, array>
     */
    public static function run(Collection $leagues, ?int $accountId = null, ?int $deckId = null): array
    {
        if ($leagues->isEmpty()) {
            return [];
        }

        $leagueIds = $leagues->pluck('id');

        $matchRows = self::getMatchRows($leagueIds, $accountId, $deckId);
        $opponentByMatch = self::getOpponentsByMatch($matchRows->pluck('id'));
        $matchesByLeague = $matchRows->groupBy('league_id');

        return $leagues
            ->filter(fn (League $league) => isset($matchesByLeague[$league->id]) && $matchesByLeague[$league->id]->isNotEmpty())
            ->values()
            ->map(fn (League $league) => self::formatRun($league, $matchesByLeague[$league->id] ?? collect(), $opponentByMatch))
            ->values()
            ->all();
    }

    private static function getMatchRows(Collection $leagueIds, ?int $accountId, ?int $deckId): Collection
    {
        return DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->whereIn('m.league_id', $leagueIds)
            ->where('m.state', 'complete')
            ->when($accountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->when($deckId, fn ($q, $id) => $q->where('d.id', $id))
            ->select('m.id', 'm.league_id', 'm.games_won', 'm.games_lost', 'm.started_at', 'd.id as deck_id', 'd.name as deck_name', 'd.color_identity as deck_color_identity')
            ->orderBy('m.started_at')
            ->get();
    }

    private static function getOpponentsByMatch(Collection $matchIds): Collection
    {
        if ($matchIds->isEmpty()) {
            return collect();
        }

        $opponentByMatch = DB::table('match_archetypes as ma')
            ->join('players as p', 'p.id', '=', 'ma.player_id')
            ->leftJoin('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('ma.mtgo_match_id', $matchIds)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereRaw('g.match_id = ma.mtgo_match_id')
                    ->whereRaw('gp.player_id = ma.player_id')
                    ->where('gp.is_local', false);
            })
            ->select('ma.mtgo_match_id', 'p.username', 'a.name as archetype_name')
            ->get()
            ->keyBy('mtgo_match_id');

        // Fallback: opponent name from game_player for matches missing archetypes
        $missingIds = $matchIds->diff($opponentByMatch->keys());
        if ($missingIds->isNotEmpty()) {
            DB::table('game_player as gp')
                ->join('games as g', 'g.id', '=', 'gp.game_id')
                ->join('players as p', 'p.id', '=', 'gp.player_id')
                ->whereIn('g.match_id', $missingIds)
                ->where('gp.is_local', false)
                ->select('g.match_id as mtgo_match_id', 'p.username')
                ->groupBy('g.match_id', 'p.username')
                ->get()
                ->each(function ($row) use ($opponentByMatch) {
                    if (! $opponentByMatch->has($row->mtgo_match_id)) {
                        $row->archetype_name = null;
                        $opponentByMatch[$row->mtgo_match_id] = $row;
                    }
                });
        }

        return $opponentByMatch;
    }

    private static function formatRun(League $league, Collection $matches, Collection $opponentByMatch): array
    {
        // Prefer league's direct deck version; fall back to most common deck in matches
        if ($league->deck_version_id && $league->deckVersion?->deck) {
            $deckModel = $league->deckVersion->deck;
            $deck = ['id' => $deckModel->id, 'name' => $deckModel->name, 'colorIdentity' => $deckModel->color_identity];
        } else {
            $deck = $matches->groupBy('deck_id')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->map(fn ($deckId) => $matches->firstWhere('deck_id', $deckId))
                ->map(fn ($row) => ['id' => $row->deck_id, 'name' => $row->deck_name, 'colorIdentity' => $row->deck_color_identity])
                ->first();
        }

        $matchData = $matches->map(function ($row) use ($opponentByMatch) {
            $opp = $opponentByMatch[$row->id] ?? null;
            $won = $row->games_won > $row->games_lost;

            return [
                'id' => $row->id,
                'result' => $won ? 'W' : 'L',
                'opponentName' => $opp?->username,
                'opponentArchetype' => $opp?->archetype_name,
                'games' => "{$row->games_won}-{$row->games_lost}",
                'startedAt' => $row->started_at,
            ];
        })->values()->all();

        $results = array_map(fn ($m) => $m['result'], $matchData);

        // Pad real leagues to 5 slots
        if (! $league->phantom) {
            while (count($results) < 5) {
                $results[] = null;
            }
        }

        // Compute version label
        $versionLabel = null;
        if ($league->deckVersion) {
            $versionIndex = $league->deckVersion->deck->versions()
                ->where('modified_at', '<=', $league->deckVersion->modified_at)
                ->count();
            $versionLabel = 'v'.$versionIndex;
        }

        return [
            'id' => $league->id,
            'name' => $league->name,
            'format' => MtgoMatch::displayFormat($league->format),
            'phantom' => (bool) $league->phantom,
            'state' => $league->state?->value ?? 'active',
            'startedAt' => $league->started_at,
            'deck' => $deck,
            'versionLabel' => $versionLabel,
            'results' => $results,
            'matches' => $matchData,
        ];
    }
}
