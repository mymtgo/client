<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\BuildMatches;
use App\Actions\Matches\ResolveGameResults;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ProcessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        BuildMatches::run();
        ResolveGameResults::run();

        return back();
    }
}
