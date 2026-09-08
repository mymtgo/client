<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\UpdateManualGameSideboard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\UpdateManualGameSideboardRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

class UpdateSideboardController extends Controller
{
    public function __invoke(Game $game, UpdateManualGameSideboardRequest $request): RedirectResponse
    {
        $handCleared = UpdateManualGameSideboard::run($game, $request->input('changes', []));

        return redirect()->back()->with('success', $handCleared
            ? 'Sideboard saved. Opening hand cleared because it used a card you sided out.'
            : 'Sideboard saved.');
    }
}
