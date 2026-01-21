<?php

namespace App\Actions\Decks;

use App\Actions\Cards\CreateMissingCards;
use App\Models\Card;
use Illuminate\Support\Collection;

class GenerateDeckSignature
{
    public static function run(Collection $cards): string
    {
        $cardIds = $cards->pluck('mtgo_id')->unique();

        CreateMissingCards::run($cardIds->toArray());

        $cardModels = Card::whereIn('mtgo_id', $cardIds)->get();

        $cardSig = $cards->map(function ($card) use ($cardModels) {
            $model = $cardModels->first(
                fn ($c) => $c->mtgo_id == $card['mtgo_id']
            );

            return collect([
                'oracle_id' => $model->oracle_id,
                'quantity' => $card['quantity'],
                'sideboard' => $card['sideboard'],
            ])->join(':');
        })->join('|');

        return base64_encode($cardSig);
    }
}
