<?php

namespace App\Actions\Matches;

use App\Models\Game;
use Illuminate\Support\Collection;

class BuildMatchGameData
{
    /**
     * Build display data for a single game within a match.
     */
    public static function run(Game $game, int $number, Collection $cardsByMtgoId, Collection $cardsByOracleId, array $registeredCards): array
    {
        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);
        $opponentPlayer = $game->players->first(fn ($p) => ! $p->pivot->is_local);

        $localInstanceId = (int) ($localPlayer?->pivot->instance_id ?? 1);
        $opponentInstanceId = (int) ($opponentPlayer?->pivot->instance_id ?? 0);

        $handData = self::parseHandData($game, $localInstanceId, $opponentInstanceId, $cardsByMtgoId);

        $opponentCardsSeen = collect($opponentPlayer?->pivot->deck_json ?? [])
            ->map(fn ($item) => [
                'name' => $cardsByMtgoId->get($item['mtgo_id'])?->name ?? "Unknown ({$item['mtgo_id']})",
                'image' => $cardsByMtgoId->get($item['mtgo_id'])?->image,
            ])
            ->unique('name')
            ->values()
            ->toArray();

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
     * Format parsed opening hand data for display.
     */
    private static function parseHandData(Game $game, int $localInstanceId, int $opponentInstanceId, Collection $cardsByMtgoId): array
    {
        $parsed = ParseOpeningHand::run($game, $localInstanceId, $opponentInstanceId);

        $toCard = fn ($catalogId, bool $bottomed = false) => [
            'name' => $cardsByMtgoId->get($catalogId)?->name ?? "Unknown ({$catalogId})",
            'image' => $cardsByMtgoId->get($catalogId)?->image,
            'bottomed' => $bottomed,
        ];

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
            ->map(fn ($catalogId) => [
                'id' => $catalogId,
                'name' => $cardsByMtgoId->get($catalogId)?->name ?? "Unknown ({$catalogId})",
                'image' => $cardsByMtgoId->get($catalogId)?->image,
            ])
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
                $type = $cardsByMtgoId->get((int) $card['CatalogID'])?->type ?? '';

                return str_contains($type, 'Land');
            })
            ->count();

        return $landCount > 0 ? $landCount : null;
    }

    /**
     * Compute sideboard changes relative to the registered deck version.
     */
    private static function computeSideboardChanges(array $gameDeckJson, array $registeredCards, Collection $cardsByMtgoId, Collection $cardsByOracleId): array
    {
        if (empty($gameDeckJson) || empty($registeredCards)) {
            return [];
        }

        $gameMains = [];
        foreach ($gameDeckJson as $item) {
            if (! ($item['sideboard'] ?? false)) {
                $oracleId = $cardsByMtgoId->get($item['mtgo_id'])?->oracle_id ?? "mtgo_{$item['mtgo_id']}";
                $gameMains[$oracleId] = ($gameMains[$oracleId] ?? 0) + (int) ($item['quantity'] ?? 1);
            }
        }

        $registeredMains = [];
        foreach ($registeredCards as $item) {
            if (($item['sideboard'] ?? 'false') === 'false') {
                $registeredMains[$item['oracle_id']] = (int) $item['quantity'];
            }
        }

        $changes = [];

        foreach ($gameMains as $oracleId => $gameQty) {
            $registeredQty = $registeredMains[$oracleId] ?? 0;
            if ($gameQty > $registeredQty) {
                $card = $cardsByOracleId->get($oracleId)
                    ?? $cardsByMtgoId->first(fn ($c) => $c->oracle_id === $oracleId);
                $changes[] = [
                    'name' => $card->name ?? 'Unknown',
                    'image' => $card?->image,
                    'quantity' => $gameQty - $registeredQty,
                    'type' => 'in',
                ];
            }
        }

        foreach ($registeredMains as $oracleId => $registeredQty) {
            $gameQty = $gameMains[$oracleId] ?? 0;
            if ($registeredQty > $gameQty) {
                $card = $cardsByOracleId->get($oracleId)
                    ?? $cardsByMtgoId->first(fn ($c) => $c->oracle_id === $oracleId);
                $changes[] = [
                    'name' => $card->name ?? 'Unknown',
                    'image' => $card?->image,
                    'quantity' => $registeredQty - $gameQty,
                    'type' => 'out',
                ];
            }
        }

        return $changes;
    }
}
