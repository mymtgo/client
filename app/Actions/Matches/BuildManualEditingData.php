<?php

namespace App\Actions\Matches;

use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

class BuildManualEditingData
{
    /**
     * Options the manual match dialogs pick from: the registered deck version
     * split into mains and sideboard, plus the opponent archetype decklist.
     *
     * @param  Collection<int|string, Card>  $cardsByMtgoId
     * @return array{deck: array{mains: list<array<string, mixed>>, sideboard: list<array<string, mixed>>}, archetypeDecklist: list<array<string, mixed>>|null}|null
     */
    public static function run(MtgoMatch $match, ?DeckVersion $version, Collection $cardsByMtgoId): ?array
    {
        if (! $match->manual) {
            return null;
        }

        $mains = [];
        $sideboard = [];

        foreach ($version?->cards ?? [] as $row) {
            if (! isset($row['mtgo_id'])) {
                continue;
            }

            $mtgoId = (int) $row['mtgo_id'];
            $card = $cardsByMtgoId->get($mtgoId);
            $entry = [
                'mtgoId' => $mtgoId,
                'name' => $card->name ?? "Unknown ({$mtgoId})",
                'image' => $card->image_url ?? null,
                'quantity' => (int) $row['quantity'],
            ];

            if (self::isSideboard($row['sideboard'] ?? false)) {
                $sideboard[] = $entry;
            } else {
                $mains[] = $entry;
            }
        }

        return [
            'deck' => ['mains' => $mains, 'sideboard' => $sideboard],
            'archetypeDecklist' => GetArchetypeDecklistForMatch::run($match),
        ];
    }

    private static function isSideboard(mixed $value): bool
    {
        return is_bool($value) ? $value : strtolower((string) $value) === 'true';
    }
}
