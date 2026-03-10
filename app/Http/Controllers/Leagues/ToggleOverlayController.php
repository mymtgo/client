<?php

namespace App\Http\Controllers\Leagues;

use App\Actions\Leagues\OpenOverlayWindow;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Window;

class ToggleOverlayController extends Controller
{
    public function __invoke(Request $request)
    {
        $existing = collect(Window::all())->first(fn ($w) => $w->getId() === 'overlay');

        if ($existing) {
            Window::close('overlay');
        } else {
            OpenOverlayWindow::run();
        }

        return back();
    }
}
