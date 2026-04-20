<?php

namespace App\Http\Controllers\Tournaments;

use App\Enums\TournamentTimelineEventType;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use App\Models\Player;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Models\TournamentTimelineEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function __invoke(Request $request, Tournament $tournament): Response
    {
        $latestRound = $tournament->standings()->max('round') ?? 0;

        $standingsByRound = $tournament->standings()
            ->orderBy('rank')
            ->get()
            ->groupBy('round')
            ->sortKeys();

        $rounds = $standingsByRound->keys()->sort()->values()->all();

        $timelineEvents = $tournament->timelineEvents()
            ->orderByDesc('occurred_at')
            ->get();

        $eliminatedIds = $tournament->timelineEvents()
            ->where('event_type', 'player_eliminated')
            ->pluck('login_id')
            ->filter()
            ->all();

        $yourRounds = [];
        $eliminatedAfterRound = null;

        if ($tournament->participated) {
            $yourRounds = MtgoMatch::query()
                ->where('tournament_id', $tournament->id)
                ->whereNotNull('tournament_round')
                ->orderBy('tournament_round')
                ->with(['games.players', 'deckVersion.deck'])
                ->get()
                ->map(function (MtgoMatch $match) use ($tournament): array {
                    $opponent = $match->games
                        ->flatMap(fn ($g) => $g->players)
                        ->first(fn (Player $p) => ! $p->pivot->is_local);

                    $opponentRank = null;

                    if ($opponent?->login_id) {
                        $opponentRank = TournamentStanding::query()
                            ->where('tournament_id', $tournament->id)
                            ->where('round', $match->tournament_round)
                            ->where('login_id', $opponent->login_id)
                            ->value('rank');
                    }

                    return [
                        'match_id' => $match->id,
                        'round' => $match->tournament_round,
                        'opponent_username' => $opponent?->username,
                        'opponent_login_id' => $opponent?->login_id,
                        'opponent_rank' => $opponentRank,
                        'result' => sprintf('%d-%d-%d', $match->games_won ?? 0, $match->games_lost ?? 0, 0),
                        'deck_name' => $match->deckVersion?->deck?->name,
                        'deck_id' => $match->deckVersion?->deck?->id,
                    ];
                })
                ->values()
                ->all();

            $localLoginId = TournamentStanding::query()
                ->where('tournament_id', $tournament->id)
                ->where('is_local', true)
                ->value('login_id');

            if ($localLoginId) {
                $eliminatedAfterRound = TournamentTimelineEvent::query()
                    ->where('tournament_id', $tournament->id)
                    ->where('event_type', TournamentTimelineEventType::PlayerEliminated->value)
                    ->where('login_id', $localLoginId)
                    ->value('round');
            }
        }

        return Inertia::render('tournaments/Show', [
            'tournament' => $tournament,
            'standingsByRound' => $standingsByRound,
            'rounds' => $rounds,
            'timelineEvents' => $timelineEvents,
            'eliminatedIds' => $eliminatedIds,
            'latestRound' => $latestRound,
            'fromDeck' => $request->input('deck_id'),
            'yourRounds' => $yourRounds,
            'eliminatedAfterRound' => $eliminatedAfterRound,
        ]);
    }
}
