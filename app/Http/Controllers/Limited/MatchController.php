<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Actions\Matches\BuildMatchShowProps;
use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class MatchController extends Controller
{
    /**
     * One match of a limited league, inside the event's own layout so the
     * player never drops out of the draft context into the deck view.
     */
    public function __invoke(League $league, MtgoMatch $match): Response
    {
        abort_unless($league->kind->isLimited(), 404);
        abort_unless($match->league_id === $league->id, 404);

        return Inertia::render('limited/Match', [
            'event' => fn () => GetLimitedEventSharedProps::run($league)['event'],
            'currentPage' => 'matches',
            ...BuildMatchShowProps::run($match),
        ]);
    }
}
