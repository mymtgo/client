<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverlayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $league = League::withCount([
            'matches as wins_count' => fn ($q) => $q->where('state', MatchState::Complete)->whereColumn('games_won', '>', 'games_lost'),
            'matches as losses_count' => fn ($q) => $q->where('state', MatchState::Complete)->whereColumn('games_lost', '>', 'games_won'),
            'matches as total_matches_count' => fn ($q) => $q->where('state', '!=', MatchState::Voided),
        ])
            ->has('matches')
            ->whereHas('matches', fn ($q) => $q->where('state', MatchState::Complete), '<', 5)
            ->latest('started_at')
            ->first();

        if (! $league) {
            return Inertia::render('leagues/Overlay', [
                'league' => null,
            ]);
        }

        $currentMatch = $league->matches()
            ->whereIn('state', [MatchState::Started, MatchState::InProgress])
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
        ]);
    }
}
