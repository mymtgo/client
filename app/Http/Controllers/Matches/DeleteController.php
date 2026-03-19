<?php

namespace App\Http\Controllers\Matches;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;

class DeleteController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $match = MtgoMatch::findOrFail($id);
        $match->update(['state' => MatchState::Voided]);

        return back();
    }
}
