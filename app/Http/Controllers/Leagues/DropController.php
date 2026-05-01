<?php

namespace App\Http\Controllers\Leagues;

use App\Enums\LeagueState;
use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\RedirectResponse;

class DropController extends Controller
{
    public function __invoke(League $league): RedirectResponse
    {
        $league->update([
            'state' => LeagueState::Dropped,
            'dropped_at' => now(),
        ]);

        return redirect()->back();
    }
}
