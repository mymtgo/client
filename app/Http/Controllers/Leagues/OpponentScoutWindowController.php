<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FetchOpponentLeagueArchetype;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Opponent;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class OpponentScoutWindowController extends Controller
{
    public function __invoke(): Response
    {
        $currentMatch = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->with('opponent')
            ->latest('started_at')
            ->first();

        $opponent = null;

        if ($currentMatch && $currentMatch->opponent) {
            $opponent = $this->buildOpponentPayload($currentMatch, $currentMatch->opponent);
        }

        return Inertia::render('leagues/OpponentScout', [
            'opponent' => $opponent,
        ]);
    }

    /**
     * @return array{username: string, previousMatches: int, wins: int, losses: int, lastArchetype: ?string, lastArchetypeColors: ?string, source: string}
     */
    private function buildOpponentPayload(MtgoMatch $currentMatch, Opponent $opponent): array
    {
        $leagueArchetype = Cache::remember(
            $opponent->username.'_archetype',
            now()->addHour(),
            fn () => FetchOpponentLeagueArchetype::run($opponent->username, $currentMatch->format) ?? false,
        );

        if ($leagueArchetype) {
            return [
                'username' => $opponent->username,
                'previousMatches' => 0,
                'wins' => 0,
                'losses' => 0,
                'lastArchetype' => $leagueArchetype['name'],
                'lastArchetypeColors' => $leagueArchetype['colors'],
                'source' => 'league',
            ];
        }

        $previousMatches = MtgoMatch::complete()
            ->where('opponent_id', $opponent->id)
            ->where('matches.id', '!=', $currentMatch->id);

        $wins = (clone $previousMatches)->where('outcome', MatchOutcome::Win)->count();
        $losses = (clone $previousMatches)->where('outcome', MatchOutcome::Loss)->count();

        $lastArchetype = MatchArchetype::whereHas('match', fn ($q) => $q->where('opponent_id', $opponent->id))
            ->where('is_opponent', true)
            ->with('archetype')
            ->latest('id')
            ->first()
            ?->archetype;

        return [
            'username' => $opponent->username,
            'previousMatches' => $wins + $losses,
            'wins' => $wins,
            'losses' => $losses,
            'lastArchetype' => $lastArchetype?->name,
            'lastArchetypeColors' => $lastArchetype?->color_identity,
            'source' => 'local',
        ];
    }
}
