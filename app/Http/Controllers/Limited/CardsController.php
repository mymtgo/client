<?php

namespace App\Http\Controllers\Limited;

use App\Actions\Limited\Read\BuildLimitedCardRows;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
use App\Http\Controllers\Controller;
use App\Models\League;
use Inertia\Inertia;
use Inertia\Response;

class CardsController extends Controller
{
    /**
     * Card-level stats for a limited league. The table walks every pick and
     * every registered version, so it is deferred behind the page shell.
     */
    public function __invoke(League $league): Response
    {
        abort_unless($league->kind->isLimited(), 404);

        return Inertia::render('limited/Cards', [
            'event' => fn () => GetLimitedEventSharedProps::run($league)['event'],
            'currentPage' => 'cards',
            'table' => Inertia::defer(fn (): array => BuildLimitedCardRows::run($league)),
        ]);
    }
}
