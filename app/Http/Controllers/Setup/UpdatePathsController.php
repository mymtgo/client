<?php

namespace App\Http\Controllers\Setup;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdatePathsController extends Controller
{
    public function logPath(Request $request): RedirectResponse
    {
        $request->validate(['path' => 'required|string']);

        AppSettings::setLogPath($request->string('path')->toString());

        return redirect()->route('setup.index');
    }

    public function dataPath(Request $request): RedirectResponse
    {
        $request->validate(['path' => 'required|string']);

        AppSettings::setLogDataPath($request->string('path')->toString());

        return redirect()->route('setup.index');
    }
}
