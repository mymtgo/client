<?php

namespace App\Actions\Matches;

use App\Models\ArchetypeDeck;
use App\Models\Card;
use App\Models\MtgoMatch;

class GetArchetypeDecklistForMatch
{
    /**
     * The reference decklist for the opponent's archetype: the match's pinned
     * archetype deck when set, otherwise the archetype's newest synced deck.
     *
     * @return list<array{mtgoId: int, name: string, image: string|null, quantity: int, sideboard: bool}>|null
     */
    public static function run(MtgoMatch $match): ?array
    {
        $match->loadMissing('opponentArchetypes.archetypeDeck');

        $matchArchetype = $match->opponentArchetypes->first();

        if (! $matchArchetype) {
            return null;
        }

        $deck = $matchArchetype->archetypeDeck
            ?? ArchetypeDeck::query()
                ->where('archetype_id', $matchArchetype->archetype_id)
                ->orderByDesc('last_synced_at')
                ->orderByDesc('created_at')
                ->first();

        if (! $deck) {
            return null;
        }

        $cards = $deck->cards()
            ->whereNotNull('mtgo_id')
            ->orderBy('archetype_deck_cards.sideboard')
            ->orderBy('name')
            ->get()
            ->map(fn (Card $card) => [
                'mtgoId' => (int) $card->mtgo_id,
                'name' => (string) $card->name,
                'image' => $card->image_url ?? null,
                'quantity' => (int) $card->pivot->quantity,
                'sideboard' => (bool) $card->pivot->sideboard,
            ])
            ->values()
            ->all();

        return $cards === [] ? null : $cards;
    }
}
