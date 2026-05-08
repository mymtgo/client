<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\FetchOpponentLeagueArchetype;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;
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
                /** @var Player $opponentPlayer */
                $opponent = $this->buildOpponentPayload($currentMatch, $opponentPlayer);
            }
        }

        return Inertia::render('leagues/OpponentScout', [
            'opponent' => $opponent,
        ]);
    }

    private function buildOpponentPayload(MtgoMatch $currentMatch, Player $opponentPlayer): array
    {
        $leagueArchetype = Cache::remember(
            $opponentPlayer->username.'_archetype',
            now()->addHour(),
            fn () => FetchOpponentLeagueArchetype::run($opponentPlayer->username, $currentMatch->format) ?? false,
        );

        if ($leagueArchetype) {
            return [
                'username' => $opponentPlayer->username,
                'previousMatches' => 0,
                'wins' => 0,
                'losses' => 0,
                'lastArchetype' => $leagueArchetype['name'],
                'lastArchetypeColors' => $leagueArchetype['colors'],
                'source' => 'league',
            ];
        }

        $previousMatches = MtgoMatch::complete()
            ->whereHas('games.opponents', fn ($q) => $q->where('players.id', $opponentPlayer->id))
            ->where('matches.id', '!=', $currentMatch->id);

        $wins = (clone $previousMatches)->where('outcome', MatchOutcome::Win)->count();
        $losses = (clone $previousMatches)->where('outcome', MatchOutcome::Loss)->count();

        $lastArchetype = $opponentPlayer->matchArchetypes()
            ->with('archetype')
            ->latest('id')
            ->first()
            ?->archetype;

        return [
            'username' => $opponentPlayer->username,
            'previousMatches' => $wins + $losses,
            'wins' => $wins,
            'losses' => $losses,
            'lastArchetype' => $lastArchetype?->name,
            'lastArchetypeColors' => $lastArchetype?->color_identity,
            'source' => 'local',
        ];
    }
}
