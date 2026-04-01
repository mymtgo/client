<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ClearLocalCardImages;
use App\Jobs\DownloadAllCardImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateLocalImagesController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = $request->boolean('enabled');

        Settings::set('local_images', $enabled ? 1 : 0);

        if ($enabled) {
            DownloadAllCardImages::dispatch();
        } else {
            ClearLocalCardImages::dispatch();
        }

        return back();
    }
}
