<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Data\Front\MatchData;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\League;
use Inertia\Inertia;
use Inertia\Response;

class MatchesController extends Controller
{
    /**
     * Match history for a limited league.
     */
    public function __invoke(League $league): Response
    {
        abort_unless($league->kind->isLimited(), 404);

        $matches = $league->matches()
            ->where('state', MatchState::Complete)
            ->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
            ->withGameCounts()
            ->orderBy('started_at')
            ->get();

        $league->loadMissing('deckVersion.deck');
        $run = FormatLeagueRuns::run(collect([$league]))[0] ?? null;

        $first = $matches->first();
        $last = $matches->last();
        $end = $last?->ended_at ?? $last?->started_at;

        return Inertia::render('limited/Matches', [
            'event' => fn () => GetLimitedEventSharedProps::run($league)['event'],
            'currentPage' => 'matches',
            'matches' => MatchData::collect($matches),
            'kpis' => [
                'wins' => $matches->where('outcome', MatchOutcome::Win)->count(),
                'losses' => $matches->where('outcome', MatchOutcome::Loss)->count(),
                'gameWins' => $run['gameWins'] ?? 0,
                'gameLosses' => $run['gameLosses'] ?? 0,
                'avgMatchSeconds' => $run['avgMatchSeconds'] ?? null,
                'onPlay' => $run['onPlayRecord'] ?? ['wins' => 0, 'losses' => 0],
                'onDraw' => $run['onDrawRecord'] ?? ['wins' => 0, 'losses' => 0],
                'queuedAt' => $first?->started_at?->toIso8601String(),
                'finishedAt' => $end?->toIso8601String(),
                'totalMinutes' => $first && $end ? (int) round($first->started_at->diffInSeconds($end, true) / 60) : null,
            ],
        ]);
    }
}
