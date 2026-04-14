<?php

namespace App\Http\Controllers\Debug\Decks;

use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class SyncController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Mtgo::syncDecks(sync: true);

        return back();
    }
}
