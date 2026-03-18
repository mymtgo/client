<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Settings;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $hidePhantom = (bool) Settings::get('hide_phantom_leagues');
        $activeAccountId = Account::active()->value('id');

        $leagues = League::query()
            ->when($hidePhantom, fn ($q) => $q->where('phantom', false))
            ->whereHas('matches', fn ($q) => $q->where('state', 'complete')->whereNull('deleted_at'))
            ->with(['deckVersion.deck'])
            ->orderByDesc('started_at')
            ->get();

        if ($leagues->isEmpty()) {
            return Inertia::render('leagues/Index', ['leagues' => []]);
        }

        $leagueIds = $leagues->pluck('id');

        // All non-deleted matches per league with deck info
        $matchRows = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->whereIn('m.league_id', $leagueIds)
            ->whereNull('m.deleted_at')
            ->where('m.state', 'complete')
            ->when($activeAccountId, fn ($q, $id) => $q->where('d.account_id', $id))
            ->select('m.id', 'm.league_id', 'm.games_won', 'm.games_lost', 'm.started_at', 'd.id as deck_id', 'd.name as deck_name', 'd.color_identity as deck_color_identity')
            ->orderBy('m.started_at')
            ->get();

        $matchIds = $matchRows->pluck('id');

        // Opponent name + archetype per match (non-local players only)
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

        $matchesByLeague = $matchRows->groupBy('league_id');

        $runs = $leagues->map(function (League $league) use ($matchesByLeague, $opponentByMatch) {
            $matches = $matchesByLeague[$league->id] ?? collect();

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
        })->values()->all();

        return Inertia::render('leagues/Index', [
            'leagues' => $runs,
            'hidePhantomLeagues' => $hidePhantom,
        ]);
    }
}
