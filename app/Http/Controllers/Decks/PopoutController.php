<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\BuildDecklist;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class PopoutController extends Controller
{
    public function __invoke(Deck $deck): Response
    {
        $deckVersion = $deck->latestVersion;

        [$mainDeck, $sideboard] = BuildDecklist::run($deckVersion);

        return Inertia::render('decks/Popout', [
            'deckName' => $deck->name,
            'format' => $deck->format,
            'maindeck' => $mainDeck,
            'sideboard' => $sideboard,
        ]);
    }
}
