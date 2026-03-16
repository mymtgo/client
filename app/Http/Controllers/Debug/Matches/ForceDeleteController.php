<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\PurgeMatch;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\RedirectResponse;

class ForceDeleteController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        $match = MtgoMatch::withTrashed()->findOrFail($id);

        PurgeMatch::run($match);

        return back();
    }
}
