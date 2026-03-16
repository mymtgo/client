<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\PurgeMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ResetController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
        ]);

        $identifier = $request->input('identifier');

        $match = MtgoMatch::withTrashed()
            ->where('mtgo_id', $identifier)
            ->orWhere('token', $identifier)
            ->first();

        if ($match) {
            $resetCount = PurgeMatch::run($match);
        } else {
            $resetCount = PurgeMatch::resetEventsByIdentifier($identifier);
        }

        return back()->with('resetCount', $resetCount);
    }
}
