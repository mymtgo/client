<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class UnlinkMatchController extends Controller
{
    public function __invoke(League $league, MtgoMatch $mtgoMatch): RedirectResponse
    {
        if (! $league->manual) {
            throw ValidationException::withMessages(['match' => 'League is not manual.']);
        }

        if ($mtgoMatch->league_id !== $league->id) {
            throw ValidationException::withMessages(['match' => 'Match does not belong to this league.']);
        }

        $mtgoMatch->update(['league_id' => null]);

        return redirect()->back();
    }
}
