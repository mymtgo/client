<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Settings;

class OverlayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $league = League::withCount([
            'matches as wins_count' => fn ($q) => $q->where('state', MatchState::Complete)->whereColumn('games_won', '>', 'games_lost'),
            'matches as losses_count' => fn ($q) => $q->where('state', MatchState::Complete)->whereColumn('games_lost', '>', 'games_won'),
            'matches as total_matches_count',
        ])
            ->has('matches')
            ->whereHas('matches', fn ($q) => $q->where('state', MatchState::Complete), '<', 5)
            ->latest('started_at')
            ->first();

        if (! $league) {
            return Inertia::render('leagues/Overlay', [
                'league' => null,
                'opponent' => null,
            ]);
        }

        $currentMatch = $league->matches()
            ->where('state', '!=', MatchState::Complete)
            ->first();

        $games = $currentMatch
            ? $currentMatch->games()->orderBy('started_at')->get()->map(fn ($game) => [
                'won' => $game->won,
                'ended' => $game->ended_at !== null,
            ])->values()->all()
            : [];

        $deckName = $league->matches()
            ->whereNotNull('deck_version_id')
            ->with('deck')
            ->first()
            ?->deck?->name;

        // Opponent scouting data
        $opponent = null;
        if ($currentMatch && (bool) Settings::get('overlay_opponent_enabled')) {
            $opponentPlayer = $currentMatch->games()
                ->first()
                ?->opponents()
                ->first();

            if ($opponentPlayer) {
                // Count previous matches against this opponent
                $previousMatches = MtgoMatch::complete()
                    ->whereHas('games.opponents', fn ($q) => $q->where('players.id', $opponentPlayer->id))
                    ->where('matches.id', '!=', $currentMatch->id);

                $wins = (clone $previousMatches)->whereRaw('games_won > games_lost')->count();
                $losses = (clone $previousMatches)->whereRaw('games_won < games_lost')->count();
                $totalPrevious = $wins + $losses;

                // Last known archetype for this opponent
                $lastArchetype = $opponentPlayer->matchArchetypes()
                    ->with('archetype')
                    ->latest('id')
                    ->first()
                    ?->archetype;

                $opponent = [
                    'username' => $opponentPlayer->username,
                    'previousMatches' => $totalPrevious,
                    'wins' => $wins,
                    'losses' => $losses,
                    'lastArchetype' => $lastArchetype?->name,
                    'lastArchetypeColors' => $lastArchetype?->color_identity,
                ];
            }
        }

        return Inertia::render('leagues/Overlay', [
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
                'format' => $league->format,
                'wins' => $league->wins_count,
                'losses' => $league->losses_count,
                'totalMatches' => $league->total_matches_count,
                'phantom' => (bool) $league->phantom,
                'deckName' => $deckName,
                'hasActiveMatch' => $currentMatch !== null,
                'games' => $games,
            ],
            'opponent' => $opponent,
        ]);
    }
}
