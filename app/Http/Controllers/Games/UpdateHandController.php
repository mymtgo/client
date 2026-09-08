<?php

namespace App\Http\Controllers\Games;

use App\Actions\Games\UpdateManualGameHand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\UpdateManualGameHandRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

class UpdateHandController extends Controller
{
    public function __invoke(Game $game, UpdateManualGameHandRequest $request): RedirectResponse
    {
        UpdateManualGameHand::run(
            $game,
            $request->integer('mulligan_count'),
            $request->input('kept_hand', []),
            $request->input('bottomed', []),
            $request->input('mulliganed_hands', []),
        );

        return redirect()->back()->with('success', 'Opening hand saved.');
    }
}
