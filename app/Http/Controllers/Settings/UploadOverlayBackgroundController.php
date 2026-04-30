<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadOverlayBackgroundController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
        ]);

        $disk = Storage::disk('overlay');

        $previous = AppSettings::overlayBackgroundPath();
        if ($previous && $disk->exists($previous)) {
            $disk->delete($previous);
        }

        $upload = $request->file('image');
        $extension = strtolower($upload->getClientOriginalExtension() ?: $upload->extension());
        $filename = 'background-'.now()->timestamp.'.'.$extension;

        $disk->putFileAs('', $upload, $filename);

        AppSettings::setOverlayBackgroundPath($filename);

        return back();
    }
}
