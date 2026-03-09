<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Data\Front\CardData;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Inertia\Inertia;
use Inertia\Response;

class PopoutController extends Controller
{
    public function __invoke(Deck $deck): Response
    {
        $deckVersion = $deck->latestVersion;

        [$mainDeck, $sideboard] = $this->buildDecklist($deckVersion);

        return Inertia::render('decks/Popout', [
            'deckName' => $deck->name,
            'format' => $deck->format,
            'maindeck' => $mainDeck,
            'sideboard' => $sideboard,
        ]);
    }

    private function buildDecklist(DeckVersion $deckVersion): array
    {
        $cards = GetCards::run($deckVersion->cards);

        $deckCards = collect($deckVersion->cards)->map(function ($card) use ($cards) {
            $cardModel = $cards->first(fn ($c) => $c->oracle_id == $card['oracle_id']);

            if (! $cardModel) {
                return null;
            }

            $cardModel = clone $cardModel;
            $cardModel->sideboard = $card['sideboard'] === 'true';
            $cardModel->quantity = $card['quantity'];

            return CardData::from($cardModel);
        })->filter()->sortBy('type')->values();

        $mainDeck = $deckCards->filter(fn ($c) => ! $c->sideboard)
            ->groupBy('type')
            ->sortBy(fn ($cards, $type) => match ($type) {
                'Creature' => 1, 'Instant' => 2, 'Sorcery' => 3, 'Land' => 10, default => 5
            });

        $sideboard = $deckCards->filter(fn ($c) => (bool) $c->sideboard)->values();

        return [$mainDeck, $sideboard];
    }
}
