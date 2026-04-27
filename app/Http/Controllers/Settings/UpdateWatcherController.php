<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateWatcherController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        $active = $request->boolean('active');

        if ($active) {
            $logOk = ValidatePath::forLogs(AppSettings::logPath());
            $dataOk = ValidatePath::forData(AppSettings::logDataPath());

            if (! $logOk['valid'] || ! $dataOk['valid']) {
                return back();
            }
        }

        AppSettings::setWatcherActive($active);

        return back();
    }
}
