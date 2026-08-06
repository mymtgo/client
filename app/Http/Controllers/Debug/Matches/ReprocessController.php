<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\ReprocessMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class ReprocessController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::findOrFail($id);

        if (! ReprocessMatch::run($match)) {
            return back()->withErrors([
                'reprocess' => 'Match has no log events to rebuild from.',
            ]);
        }

        return back();
    }
}
