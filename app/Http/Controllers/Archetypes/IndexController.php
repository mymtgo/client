<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetFilteredArchetypes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('archetypes/Index', GetFilteredArchetypes::run($request));
    }
}
