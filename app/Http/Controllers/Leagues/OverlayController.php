<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverlayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $league = League::withCount([
            'matches as wins_count' => fn ($q) => $q->where('state', MatchState::Complete)->where('outcome', 'win'),
            'matches as losses_count' => fn ($q) => $q->where('state', MatchState::Complete)->where('outcome', 'loss'),
            'matches as total_matches_count' => fn ($q) => $q->where('state', '!=', MatchState::Voided),
        ])
            ->with(['deckVersion.deck'])
            ->where('leagues.state', LeagueState::Active)
            ->has('matches')
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

        /** @var \App\Models\Deck|null $deckModel */
        $deckModel = $league->deckVersion?->deck;
        if (! $deckModel) {
            /** @var \App\Models\Deck|null $deckModel */
            $deckModel = $league->matches()
                ->whereNotNull('deck_version_id')
                ->with('deck')
                ->first()
                ?->getRelation('deck');
        }
        $deckName = $deckModel?->name;

        return Inertia::render('leagues/Overlay', [
            'league' => [
                'id' => $league->id,
                'name' => $league->name,
                'format' => MtgoMatch::displayFormat($league->format),
                'wins' => $league->wins_count,
                'losses' => $league->losses_count,
                'totalMatches' => $league->total_matches_count,
                'phantom' => (bool) $league->phantom,
                'deckName' => $deckName,
                'hasActiveMatch' => ! is_null($currentMatch),
                'games' => $games,
            ],
        ]);
    }
}
