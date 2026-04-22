<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateLogPathController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        AppSettings::setLogPath($path);

        $status = ValidatePath::forLogs($path);

        if (! $status['valid']) {
            AppSettings::setWatcherActive(false);
        }

        return back();
    }
}
