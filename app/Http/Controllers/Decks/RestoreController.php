<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class RestoreController extends Controller
{
    public function __invoke(Deck $deck): RedirectResponse
    {
        if ($deck->trashed()) {
            $deck->restore();
        }

        return back();
    }
}
