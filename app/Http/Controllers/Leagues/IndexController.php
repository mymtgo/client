<?php

namespace App\Http\Controllers\Leagues;

use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(): Response
    {
        return Inertia::render('leagues/Index');
    }
}
