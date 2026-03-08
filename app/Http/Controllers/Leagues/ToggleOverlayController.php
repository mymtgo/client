<?php

namespace App\Http\Controllers\Leagues;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Window;

class ToggleOverlayController extends Controller
{
    public function __invoke(Request $request)
    {
        $existing = collect(Window::all())->firstWhere('id', 'overlay');

        if ($existing) {
            Window::close('overlay');
        } else {
            Window::open('overlay')
                ->route('leagues.overlay')
                ->width(300)
                ->height(80)
                ->minWidth(200)
                ->minHeight(60)
                ->alwaysOnTop()
                ->frameless()
                ->resizable()
                ->maximizable(false)
                ->fullscreenable(false)
                ->hideMenu()
                ->showDevTools(false)
                ->title('League Overlay');
        }

        return back();
    }
}
