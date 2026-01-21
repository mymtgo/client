<?php

namespace App\Actions;

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\Deck;
use App\Models\MtgoMatch;

class MatchGameDeck
{
    public static function run(MtgoMatch $match)
    {
        $firstGame = $match->games->first()->load('deck.cards.card');

        $deckCards = $firstGame->deck->cards->map(function ($gameDeckCard) {
            return [
                'mtgo_id' => $gameDeckCard->card->mtgo_id,
                'quantity' => $gameDeckCard->quantity,
                'sideboard' => $gameDeckCard->sideboard,
            ];
        })->toArray();

        $signature = GenerateDeckSignature::run($deckCards);

        foreach (Deck::all() as $deck) {
            $gameDeckCards = $deck->cards->map(function ($card) {
                return [
                    'mtgo_id' => $card->mtgo_id,
                    'quantity' => $card->pivot->quantity,
                    'sideboard' => (bool) $card->pivot->sideboard,
                ];
            });

            $sig = GenerateDeckSignature::run($gameDeckCards->toArray());

            if ($sig == $signature) {
                $match->update([
                    'deck_id' => $deck->id,
                ]);
            }
        }
    }
}
