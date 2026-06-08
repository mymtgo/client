<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\ComputeDrawOdds;
use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class PopoutController extends Controller
{
    public function __invoke(): Response
    {
        $currentMatch = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->latest('started_at')
            ->first();

        return Inertia::render('decks/Popout', [
            'drawOdds' => $currentMatch ? ComputeDrawOdds::run($currentMatch) : null,
        ]);
    }
}
