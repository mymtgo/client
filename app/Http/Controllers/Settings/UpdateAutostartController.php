<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\App as NativeApp;

class UpdateAutostartController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = $request->boolean('enabled');

        AppSettings::setAutostartEnabled($enabled);

        if (PHP_OS_FAMILY !== 'Linux') {
            try {
                NativeApp::openAtLogin($enabled);
            } catch (\Throwable) {
                // Native bridge unavailable in HTTP test context; setting still persists.
            }
        }

        return back();
    }
}
