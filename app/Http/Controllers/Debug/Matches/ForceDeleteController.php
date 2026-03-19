<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class ForceDeleteController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::findOrFail($id);
        $match->delete();

        return back();
    }
}
