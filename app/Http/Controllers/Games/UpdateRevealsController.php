<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\UpdateManualGameReveals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\UpdateManualGameRevealsRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

class UpdateRevealsController extends Controller
{
    public function __invoke(Game $game, UpdateManualGameRevealsRequest $request): RedirectResponse
    {
        UpdateManualGameReveals::run($game, $request->input('cards', []));

        return redirect()->back()->with('success', 'Revealed cards saved.');
    }
}
