<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Matches\CreateManualMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Matches\StoreManualMatchRequest;
use Illuminate\Http\RedirectResponse;

class StoreController extends Controller
{
    public function __invoke(StoreManualMatchRequest $request): RedirectResponse
    {
        CreateManualMatch::run($request->validated());

        return redirect()->back()->with('success', 'Manual match added.');
    }
}
