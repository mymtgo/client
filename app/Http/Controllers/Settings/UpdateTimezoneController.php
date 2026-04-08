<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
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

        $timezone = $request->input('timezone');

        Settings::set('timezone', $timezone);
        AppSetting::resolve()->update(['timezone' => $timezone]);

        return back();
    }
}
