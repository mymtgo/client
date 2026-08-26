<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Limited\Read\BuildDeckEvolution;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Http\Controllers\Controller;
use App\Models\League;
use Inertia\Inertia;
use Inertia\Response;

class DeckController extends Controller
{
    /**
     * Deck evolution view for a limited league: the drafted pool, every
     * registered version and the per game sideboarding behind them.
     */
    public function __invoke(League $league): Response
    {
        abort_unless($league->kind->isLimited(), 404);

        return Inertia::render('limited/Deck', [
            'event' => fn () => GetLimitedEventSharedProps::run($league)['event'],
            'currentPage' => 'deck',
            'evolution' => Inertia::defer(fn () => BuildDeckEvolution::run($league)),
        ]);
    }
}
