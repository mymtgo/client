<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class RestoreController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::findOrFail($id);
        $match->update(['state' => MatchState::Complete]);

        return back();
    }
}
