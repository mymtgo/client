<?php

namespace App\Http\Controllers\Limited;

use App\Http\Controllers\Controller;
use App\Http\Requests\Limited\UpdatePickNoteRequest;
use App\Models\League;
use Illuminate\Http\RedirectResponse;

class UpdatePickNoteController extends Controller
{
    public function __invoke(UpdatePickNoteRequest $request, League $league, int $ordinal): RedirectResponse
    {
        abort_unless($league->kind->isLimited(), 404);

        $draft = $league->draft;
        abort_unless($draft && $ordinal >= 1 && $ordinal <= (int) $draft->picks_expected, 404);

        $pick = $draft->picks()->where('ordinal', $ordinal)->first();
        abort_if($pick === null, 404);

        $pick->update(['note' => $request->note()]);

        return back();
    }
}
