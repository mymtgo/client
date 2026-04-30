<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DeleteOverlayBackgroundController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $disk = Storage::disk('overlay');

        $path = AppSettings::overlayBackgroundPath();
        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }

        AppSettings::setOverlayBackgroundPath(null);

        return back();
    }
}
