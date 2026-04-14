<?php

namespace App\Actions\Decks;

use App\Actions\Cards\GetCards;
use App\Data\Front\CardData;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;

class BuildDecklist
{
    /**
     * Build a formatted decklist from a deck version.
     *
     * @return array{0: Collection, 1: Collection} [mainDeck grouped by type, sideboard]
     */
    public static function run(DeckVersion $deckVersion): array
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
