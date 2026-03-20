<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class OpponentScoutWindowController extends Controller
{
    public function __invoke(): Response
    {
        $currentMatch = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->latest('started_at')
            ->first();

        $opponent = null;

        if ($currentMatch) {
            $opponentPlayer = $currentMatch->games()
                ->first()
                ?->opponents()
                ->first();

            if ($opponentPlayer) {
                $previousMatches = MtgoMatch::complete()
                    ->whereHas('games.opponents', fn ($q) => $q->where('players.id', $opponentPlayer->id))
                    ->where('matches.id', '!=', $currentMatch->id);

                $wins = (clone $previousMatches)->where('outcome', 'win')->count();
                $losses = (clone $previousMatches)->where('outcome', 'loss')->count();
                $totalPrevious = $wins + $losses;

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

        return Inertia::render('leagues/OpponentScout', [
            'opponent' => $opponent,
        ]);
    }
}
