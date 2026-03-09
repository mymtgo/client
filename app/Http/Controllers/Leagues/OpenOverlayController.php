<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\OpenOverlayWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OpenOverlayController extends Controller
{
    public function __invoke(Request $request)
    {
        OpenOverlayWindow::run();

        return back();
    }
}
