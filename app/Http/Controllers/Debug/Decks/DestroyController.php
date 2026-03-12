<?php

namespace App\Http\Controllers\Debug\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    public function __invoke(int $id): RedirectResponse
    {
        Deck::findOrFail($id)->delete();

        return back();
    }
}
