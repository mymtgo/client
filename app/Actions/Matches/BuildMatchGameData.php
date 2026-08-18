<?php

namespace App\Actions\Matches;

use App\Models\Game;
use Illuminate\Support\Collection;

class BuildMatchGameData
{
    /**
     * Build display data for a single game within a match.
     *
     * @param  array<int, array<string, mixed>>  $opponentLogCards  cards attributed to the opponent
     *                                                              by ExtractCardsFromGameLog for this game
     */
    public static function run(Game $game, int $number, Collection $cardsByMtgoId, Collection $cardsByOracleId, array $registeredCards, array $opponentLogCards = []): array
    {
        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);
        $opponentPlayer = $game->players->first(fn ($p) => ! $p->pivot->is_local);

        $localInstanceId = (int) ($localPlayer?->pivot->instance_id ?? 1);
        $opponentInstanceId = (int) ($opponentPlayer?->pivot->instance_id ?? 0);

        $handData = self::parseHandData($game, $localInstanceId, $opponentInstanceId, $cardsByMtgoId);

        $opponentCardsSeen = collect($opponentPlayer?->pivot->deck_json ?? [])
            ->groupBy('mtgo_id')
            ->map(function ($items, $mtgoId) use ($cardsByMtgoId) {
                $card = $cardsByMtgoId->get($mtgoId);
                $quantity = collect($items)->sum(fn ($i) => (int) ($i['quantity'] ?? 1));

                return [
                    'name' => $card->name ?? "Unknown ({$mtgoId})",
                    'image' => $card->image_url ?? null,
                    'type' => $card->type ?? null,
                    'identity' => $card->color_identity ?? null,
                    'quantity' => $quantity,
                ];
            })
            ->values()
            ->toArray();

        $opponentCardsSeen = self::mergeLogOnlyCards($opponentCardsSeen, $opponentLogCards, $cardsByMtgoId);

        $sideboardChanges = self::computeSideboardChanges(
            $localPlayer?->pivot->deck_json ?? [],
            $registeredCards,
            $cardsByMtgoId,
            $cardsByOracleId,
        );

        $localCardsPlayed = self::parseLocalCardsPlayed($game, $localInstanceId, $cardsByMtgoId);

        $duration = null;
        if ($game->ended_at) {
            $totalSeconds = (int) abs($game->started_at->diffInSeconds($game->ended_at));
            $mins = intdiv($totalSeconds, 60);
            $secs = $totalSeconds % 60;
            $duration = $mins > 0 ? "{$mins}m {$secs}s" : "{$secs}s";
        }

        return [
            'id' => $game->id,
            'number' => $number,
            'won' => (bool) $game->won,
            'onThePlay' => (bool) ($localPlayer?->pivot->on_play ?? false),
            'duration' => $duration,
            'turns' => self::estimateTurns($game, $cardsByMtgoId),
            'localMulligans' => $handData['localMulligans'],
            'opponentMulligans' => $handData['opponentMulligans'],
            'mulliganedHands' => $handData['mulliganedHands'],
            'keptHand' => $handData['keptHand'],
            'sideboardChanges' => $sideboardChanges,
            'localCardsPlayed' => $localCardsPlayed,
            'opponentCardsSeen' => $opponentCardsSeen,
        ];
    }

    /**
     * Append opponent cards that appear in the game log but not in the final
     * GameCards snapshot (deck_json). Cards can leave every visible zone before
     * the last snapshot (shuffled into library, bounced to hand), and multi-face
     * casts are logged under the face's CatalogID while snapshots carry the
     * parent printing — both would otherwise vanish from the reveals list.
     *
     * @param  array<int, array<string, mixed>>  $cardsSeen
     * @param  array<int, array<string, mixed>>  $opponentLogCards
     * @return array<int, array<string, mixed>>
     */
    private static function mergeLogOnlyCards(array $cardsSeen, array $opponentLogCards, Collection $cardsByMtgoId): array
    {
        if (empty($opponentLogCards)) {
            return $cardsSeen;
        }

        $knownNames = [];
        foreach ($cardsSeen as $entry) {
            foreach (explode(' // ', mb_strtolower($entry['name'])) as $face) {
                $knownNames[trim($face)] = true;
            }
        }

        foreach ($opponentLogCards as $logCard) {
            $card = $cardsByMtgoId->get($logCard['mtgo_id']);
            $name = $card->name ?? $logCard['name'];

            $faces = explode(' // ', mb_strtolower($name));
            $alreadyShown = false;
            foreach ($faces as $face) {
                if (isset($knownNames[trim($face)])) {
                    $alreadyShown = true;

                    break;
                }
            }

            if ($alreadyShown) {
                continue;
            }

            foreach ($faces as $face) {
                $knownNames[trim($face)] = true;
            }

            $cardsSeen[] = [
                'name' => $name,
                'image' => $card->image_url ?? null,
                'type' => $card->type ?? null,
                'identity' => $card->color_identity ?? null,
                'quantity' => 1,
            ];
        }

        return $cardsSeen;
    }

    /**
     * Format parsed opening hand data for display.
     */
    private static function parseHandData(Game $game, int $localInstanceId, int $opponentInstanceId, Collection $cardsByMtgoId): array
    {
        $parsed = ParseOpeningHand::run($game, $localInstanceId, $opponentInstanceId);

        $toCard = function ($catalogId, bool $bottomed = false) use ($cardsByMtgoId) {
            $card = $cardsByMtgoId->get($catalogId);

            return [
                'name' => $card->name ?? "Unknown ({$catalogId})",
                'image' => $card->image_url ?? null,
                'bottomed' => $bottomed,
            ];
        };

        // For display: show the full hand including bottomed cards (marked)
        $displayHand = ! empty($parsed['bottomed_instance_ids']) ? $parsed['hand_before_bottoming'] : $parsed['kept_hand'];
        $keptHand = [];
        foreach ($displayHand as $instanceId => $catalogId) {
            $keptHand[] = $toCard($catalogId, in_array($instanceId, $parsed['bottomed_instance_ids']));
        }

        $mulliganedHandsFormatted = array_map(
            fn ($hand) => array_map(fn ($catalogId) => $toCard($catalogId), array_values($hand)),
            $parsed['mulliganed_hands']
        );

        return [
            'localMulligans' => count($parsed['mulliganed_hands']),
            'opponentMulligans' => $parsed['opponent_mulligans'],
            'mulliganedHands' => $mulliganedHandsFormatted,
            'keptHand' => $keptHand,
        ];
    }

    /**
     * Collect unique cards the local player played during the game.
     */
    private static function parseLocalCardsPlayed(Game $game, int $localInstanceId, Collection $cardsByMtgoId): array
    {
        $seenCatalogIds = [];

        foreach ($game->timeline->sortBy('timestamp') as $snapshot) {
            foreach ($snapshot->content['Cards'] ?? [] as $card) {
                if ((int) $card['Owner'] === $localInstanceId
                    && in_array($card['Zone'], ['Battlefield', 'Stack', 'Graveyard'])
                ) {
                    $seenCatalogIds[(int) $card['CatalogID']] = true;
                }
            }
        }

        return collect(array_keys($seenCatalogIds))
            ->map(function ($catalogId) use ($cardsByMtgoId) {
                $card = $cardsByMtgoId->get($catalogId);

                return [
                    'id' => $catalogId,
                    'name' => $card->name ?? "Unknown ({$catalogId})",
                    'image' => $card->image_url ?? null,
                ];
            })
            ->unique('id')
            ->values()
            ->toArray();
    }

    /**
     * Estimate game length in turns by counting lands on the battlefield.
     */
    private static function estimateTurns(Game $game, Collection $cardsByMtgoId): ?int
    {
        $lastSnapshot = $game->timeline->sortBy('timestamp')->last();

        if (! $lastSnapshot) {
            return null;
        }

        $landCount = collect($lastSnapshot->content['Cards'] ?? [])
            ->filter(fn ($card) => $card['Zone'] === 'Battlefield')
            ->filter(function ($card) use ($cardsByMtgoId) {
                $resolved = $cardsByMtgoId->get((int) $card['CatalogID']);

                return str_contains($resolved->type ?? '', 'Land');
            })
            ->count();

        return $landCount > 0 ? $landCount : null;
    }

    /**
     * Compute sideboard changes relative to the registered deck version.
     *
     * Keys on oracle_id when available so different printings of the same card
     * (different mtgo_ids, identical oracle_id) compare as equal. Falls back to
     * mtgo_id keying when oracle_id is missing on either side (Card row created
     * mid-pipeline before identity backfill, or legacy registered-card rows
     * with oracle_id absent). The fallback key is namespaced (`mtgo:{id}`) so
     * it cannot collide with an oracle_id value.
     */
    private static function computeSideboardChanges(array $gameDeckJson, array $registeredCards, Collection $cardsByMtgoId, Collection $cardsByOracleId): array
    {
        if (empty($gameDeckJson) || empty($registeredCards)) {
            return [];
        }

        $gameMains = [];
        foreach ($gameDeckJson as $item) {
            if ($item['sideboard'] ?? false) {
                continue;
            }

            $mtgoId = (int) ($item['mtgo_id'] ?? 0);
            if ($mtgoId === 0) {
                continue;
            }

            $card = $cardsByMtgoId->get($mtgoId);
            $key = ! empty($card?->oracle_id) ? "oracle:{$card->oracle_id}" : "mtgo:{$mtgoId}";

            if (! isset($gameMains[$key])) {
                $gameMains[$key] = ['quantity' => 0, 'mtgo_id' => $mtgoId];
            }
            $gameMains[$key]['quantity'] += (int) ($item['quantity'] ?? 1);
        }

        $registeredMains = [];
        foreach ($registeredCards as $item) {
            if (($item['sideboard'] ?? 'false') !== 'false') {
                continue;
            }

            $oracleId = $item['oracle_id'] ?? null;
            $mtgoId = isset($item['mtgo_id']) ? (int) $item['mtgo_id'] : null;

            if (! $oracleId && $mtgoId) {
                $resolved = $cardsByMtgoId->get($mtgoId);
                $oracleId = $resolved?->oracle_id ?: null;
            }

            if (! $mtgoId && $oracleId) {
                $resolved = $cardsByOracleId->get($oracleId);
                $mtgoId = $resolved?->mtgo_id !== null ? (int) $resolved->mtgo_id : null;
            }

            if (! $oracleId && ! $mtgoId) {
                continue;
            }

            $key = $oracleId ? "oracle:{$oracleId}" : "mtgo:{$mtgoId}";

            if (! isset($registeredMains[$key])) {
                $registeredMains[$key] = ['quantity' => 0, 'mtgo_id' => $mtgoId ?? 0];
            }
            $registeredMains[$key]['quantity'] += (int) $item['quantity'];
        }

        $changes = [];

        foreach ($gameMains as $key => $entry) {
            $registeredQty = $registeredMains[$key]['quantity'] ?? 0;
            if ($entry['quantity'] > $registeredQty) {
                $changes[] = self::buildSideboardChange($entry['mtgo_id'], $entry['quantity'] - $registeredQty, 'in', $cardsByMtgoId);
            }
        }

        foreach ($registeredMains as $key => $entry) {
            $gameQty = $gameMains[$key]['quantity'] ?? 0;
            if ($entry['quantity'] > $gameQty) {
                $changes[] = self::buildSideboardChange($entry['mtgo_id'], $entry['quantity'] - $gameQty, 'out', $cardsByMtgoId);
            }
        }

        return $changes;
    }

    /**
     * Build a single sideboard change entry.
     */
    private static function buildSideboardChange(int $mtgoId, int $quantity, string $type, Collection $cardsByMtgoId): array
    {
        $card = $cardsByMtgoId->get($mtgoId);

        return [
            'name' => $card->name ?? 'Unknown',
            'image' => $card->image_url ?? null,
            'quantity' => $quantity,
            'type' => $type,
        ];
    }
}
