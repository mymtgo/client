<?php

namespace App\Actions\Cards;

use App\Models\Card;
use Illuminate\Support\Facades\DB;

class SearchCards
{
    /**
     * Name search over the local card table for the manual reveals picker.
     * One row per oracle id (the highest id printing), ordered by name.
     *
     * @return list<array{mtgoId: int, oracleId: string|null, name: string, image: string|null, type: string|null}>
     */
    public static function run(string $query, int $limit = 20): array
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($query)).'%';

        $newestPerOracle = Card::query()
            ->select(DB::raw('MAX(id) as id'))
            ->whereNotNull('mtgo_id')
            ->where('name', 'like', $term)
            ->groupBy(DB::raw("COALESCE(oracle_id, 'mtgo:' || mtgo_id)"));

        return Card::query()
            ->whereIn('id', $newestPerOracle)
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Card $card) => [
                'mtgoId' => (int) $card->mtgo_id,
                'oracleId' => $card->oracle_id,
                'name' => (string) $card->name,
                'image' => $card->image_url ?? null,
                'type' => $card->type,
            ])
            ->values()
            ->all();
    }
}
