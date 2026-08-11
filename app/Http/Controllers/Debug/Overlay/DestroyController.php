<?php

namespace App\Http\Controllers\Debug\Overlay;

use App\Actions\Debug\TeardownFakeOverlayMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        TeardownFakeOverlayMatches::run();

        return back();
    }
}
