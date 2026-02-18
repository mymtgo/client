<?php

namespace App\Http\Controllers\Opponents;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        // TODO: wire up real data
        return Inertia::render('opponents/Index');
    }
}
