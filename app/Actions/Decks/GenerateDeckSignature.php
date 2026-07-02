<?php

namespace App\Actions\Decks;

use App\Actions\Cards\CreateMissingCards;
use Illuminate\Support\Collection;

class GenerateDeckSignature
{
    public static function run(Collection $cards): string
    {
        $cardIds = $cards->pluck('mtgo_id')->map(fn ($id) => (int) $id)->unique();

        CreateMissingCards::run($cardIds->toArray());

        $normalized = $cards->map(fn ($card) => [
            'mtgo_id' => (int) $card['mtgo_id'],
            'quantity' => (int) $card['quantity'],
            'sideboard' => filter_var($card['sideboard'], FILTER_VALIDATE_BOOL) ? 'true' : 'false',
        ])->sortBy([
            ['mtgo_id', 'asc'],
            ['sideboard', 'asc'],
        ])->values();

        $cardSig = $normalized
            ->map(fn ($card) => "{$card['mtgo_id']}:{$card['quantity']}:{$card['sideboard']}")
            ->join('|');

        return base64_encode($cardSig);
    }
}
