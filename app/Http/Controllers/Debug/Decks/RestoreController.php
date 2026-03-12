<?php

namespace App\Http\Controllers\Debug\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class RestoreController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        Deck::withTrashed()->findOrFail($id)->restore();

        return back();
    }
}
