<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\ClearLocalCardImages;
use App\Jobs\DownloadAllCardImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateLocalImagesController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = $request->boolean('enabled');

        AppSettings::setDownloadImagesLocally($enabled);

        if ($enabled) {
            DownloadAllCardImages::dispatch();
        } else {
            ClearLocalCardImages::dispatch();
        }

        return back();
    }
}
