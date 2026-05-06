<?php

namespace App\Http\Controllers\Decks;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ToggleHideArchivedController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'hide' => 'required|boolean',
        ]);

        AppSettings::setHideArchivedDecks($request->boolean('hide'));

        return back();
    }
}
