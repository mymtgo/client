<?php

namespace App\Http\Controllers\Decks;

use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(): Response
    {
        return Inertia::render('decks/Index');
    }
}
