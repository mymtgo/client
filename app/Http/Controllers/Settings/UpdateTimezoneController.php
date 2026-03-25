<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateTimezoneController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'timezone' => 'required|timezone',
        ]);

        Settings::set('timezone', $request->input('timezone'));

        date_default_timezone_set($request->input('timezone'));
        config(['app.timezone' => $request->input('timezone')]);

        return back();
    }
}
