<?php

namespace App\Actions\Cards;

use App\Models\Card;
use Illuminate\Support\Collection;

class GetCards
{
    /**
     * Every catalog card a deck's card refs could point at: the exact
     * printings, plus every printing sharing their oracle id.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return Collection<int, Card>
     */
    public static function run(array $cards)
    {
        $mappedIds = collect($cards)->map(function ($card) {
            return $card['oracle_id'] ?? $card['mtgo_id'];
        });

        $printingIds = collect($cards)->pluck('mtgo_id')->filter();

        return Card::whereIn('mtgo_id', $mappedIds->merge($printingIds))->orWhereIn('oracle_id', $mappedIds)->get();
    }

    /**
     * The card a single ref means: the printing it registered when the
     * catalog has it, otherwise any printing of the same card. Old oracle-id
     * signatures carry no printing, so they always take the fallback.
     *
     * @param  Collection<int, Card>  $cards
     * @param  array<string, mixed>  $ref
     */
    public static function forRef(Collection $cards, array $ref): ?Card
    {
        $mtgoId = $ref['mtgo_id'] ?? null;

        if ($mtgoId !== null) {
            $printing = $cards->first(fn (Card $card) => (int) $card->mtgo_id === (int) $mtgoId);

            if ($printing) {
                return $printing;
            }
        }

        $oracleId = $ref['oracle_id'] ?? null;

        return $oracleId === null ? null : $cards->first(fn (Card $card) => $card->oracle_id === $oracleId);
    }
}
