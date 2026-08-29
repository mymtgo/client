<?php

namespace App\Http\Controllers\Decks;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Settings\AppSettings as AppSettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateCardSizeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'size' => ['required', 'string', 'in:'.implode(',', AppSettingsStore::DECK_CARD_SIZES)],
        ]);

        AppSettings::setDeckCardSize($request->string('size')->toString());

        return back();
    }
}
