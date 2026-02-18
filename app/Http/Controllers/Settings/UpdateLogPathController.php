<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateLogPathController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'path' => 'required|string',
        ]);

        $path = $request->input('path');

        Settings::set('log_path', $path);

        $status = ValidatePath::forLogs($path);

        if (! $status['valid']) {
            Settings::set('watcher_active', false);
        }

        return back();
    }
}
