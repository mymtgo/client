<?php

namespace App\Http\Controllers\Farewell;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Native\Desktop\Facades\Shell;

/**
 * The farewell screen's "Download MyMTGO" button: opens the MyMTGO 1.0
 * download page in the user's browser, then shows the farewell screen again.
 */
class OpenDownloadController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Shell::openExternal((string) config('farewell.download_url'));

        return redirect()->route('home');
    }
}
