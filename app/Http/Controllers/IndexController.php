<?php

namespace App\Http\Controllers;

use App\Actions\Decks\SyncDecks;
use App\Actions\Matches\BuildMatch;
use App\Actions\Matches\BuildMatches;
use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Facades\Mtgo;
use App\Jobs\ProcessLogEvents;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Native\Laravel\Facades\Notification;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {

        return Inertia::render('Index', [

        ]);
    }
}
