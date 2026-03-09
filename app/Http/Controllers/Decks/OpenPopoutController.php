<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\OpenDeckPopoutWindow;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class OpenPopoutController extends Controller
{
    public function __invoke(Deck $deck): RedirectResponse
    {
        OpenDeckPopoutWindow::run($deck->id);

        return back();
    }
}
