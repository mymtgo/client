<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\BuildOverlayState;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class OverlayController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('leagues/Overlay', [
            'state' => BuildOverlayState::run(),
        ]);
    }
}
