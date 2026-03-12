<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class RestoreController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::withTrashed()->findOrFail($id);
        $match->restore();

        return back();
    }
}
