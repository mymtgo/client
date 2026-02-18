<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateWatcherController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'active' => 'required|boolean',
        ]);

        $active = $request->boolean('active');

        if ($active) {
            $logOk = ValidatePath::forLogs(Settings::get('log_path', ''));
            $dataOk = ValidatePath::forData(Settings::get('log_data_path', ''));

            if (! $logOk['valid'] || ! $dataOk['valid']) {
                return back();
            }
        }

        Settings::set('watcher_active', $active ? 1 : 0);

        return back();
    }
}
