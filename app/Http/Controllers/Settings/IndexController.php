<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        // TODO: wire up real data from NativePHP Settings / config
        return Inertia::render('settings/Index');
    }
}
