<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\RedirectResponse;

class AbandonController extends Controller
{
    public function __invoke(League $league): RedirectResponse
    {
        abort_unless($league->phantom, 403, 'Only phantom leagues can be abandoned.');

        $league->delete();

        return back();
    }
}
