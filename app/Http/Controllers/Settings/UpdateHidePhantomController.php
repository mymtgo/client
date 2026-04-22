<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateHidePhantomController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        AppSettings::setHidePhantomLeagues($request->boolean('enabled'));

        return back();
    }
}
