<?php

namespace App\Actions\Decks;

use App\Actions\Cards\GetCards;
use App\Models\Deck;

class ComputeDeckIdentity
{
    private const IGNORED_TYPES = ['Artifact', 'Land', 'Basic Land'];

    private const MIN_COUNT = 4;

    /**
     * Compute and persist the colour identity for a deck based on its latest version's cards.
     */
    public static function run(Deck $deck): void
    {
        $version = $deck->latestVersion;

        if (! $version || empty($version->cards)) {
            return;
        }

        $cards = GetCards::run($version->cards);
        $colorCounts = [];

        foreach ($version->cards as $ref) {
            $card = $cards->first(fn ($c) => $c->oracle_id === $ref['oracle_id'] || (string) $c->mtgo_id === $ref['oracle_id']);

            if (! $card || ! $card->type || in_array($card->type, self::IGNORED_TYPES)) {
                continue;
            }

            $identity = trim($card->color_identity ?? '');
            $colors = $identity ? explode(',', $identity) : ['C'];
            $qty = (int) ($ref['quantity'] ?? 1);

            foreach ($colors as $color) {
                $color = trim($color);
                if ($color) {
                    $colorCounts[$color] = ($colorCounts[$color] ?? 0) + $qty;
                }
            }
        }

        $identity = collect($colorCounts)
            ->filter(fn ($count) => $count >= self::MIN_COUNT)
            ->keys()
            ->join(',') ?: null;

        $deck->update(['color_identity' => $identity]);
    }
}
