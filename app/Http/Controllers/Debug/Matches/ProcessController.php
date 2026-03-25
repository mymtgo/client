<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Actions\Matches\BuildMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ProcessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        BuildMatches::run();

        return back();
    }
}
