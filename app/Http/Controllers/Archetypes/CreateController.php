<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetFilteredArchetypes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = GetFilteredArchetypes::run($request);

        return Inertia::render('archetypes/Create', [
            'archetypes' => $data['archetypes'],
            'formats' => $data['formats'],
            'filters' => $data['filters'],
        ]);
    }
}
