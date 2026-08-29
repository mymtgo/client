<?php

namespace App\Http\Controllers\Decks;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Settings\AppSettings as AppSettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdatePerPageController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'per_page' => ['required', 'integer', 'in:'.implode(',', AppSettingsStore::DECKS_PER_PAGE_OPTIONS)],
        ]);

        AppSettings::setDecksPerPage($request->integer('per_page'));

        return back();
    }
}
