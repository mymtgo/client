<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;

class UpdateAnonymousStatsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        Settings::set('anonymous_stats', $request->boolean('enabled') ? 1 : 0);

        return back();
    }
}
