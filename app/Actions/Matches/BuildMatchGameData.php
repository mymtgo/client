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
        if ($game->started_at && $game->ended_at) {
            $totalSeconds = abs($game->started_at->diffInSeconds($game->ended_at));
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
     * Parse opening hand data from game timeline snapshots.
     */
    private static function parseHandData(Game $game, int $localInstanceId, int $opponentInstanceId, Collection $cardsByMtgoId): array
    {
        $snapshots = $game->timeline->sortBy('timestamp');

        $mulliganedHands = [];
        $currentHandInstances = [];
        $handBeforeBottoming = [];
        $bottomedInstanceIds = [];
        $openingPhase = true;

        $opponentStartLibrary = null;
        $opponentFirstHandLibrary = null;

        foreach ($snapshots as $snapshot) {
            $content = $snapshot->content;
            $players = collect($content['Players'] ?? []);
            $cards = collect($content['Cards'] ?? []);

            $opponentState = $players->first(fn ($p) => (int) $p['Id'] === $opponentInstanceId);
            if ($opponentState) {
                $oppHand = (int) $opponentState['HandCount'];
                $oppLib = (int) $opponentState['LibraryCount'];
                if ($opponentStartLibrary === null && $oppHand === 0) {
                    $opponentStartLibrary = $oppLib;
                } elseif ($opponentFirstHandLibrary === null && $oppHand > 0) {
                    $opponentFirstHandLibrary = $oppLib;
                }
            }

            if (! $openingPhase) {
                if ($opponentFirstHandLibrary !== null) {
                    break;
                }

                continue;
            }

            $localInPlay = $cards->first(fn ($c) => (int) $c['Owner'] === $localInstanceId &&
                in_array($c['Zone'], ['Battlefield', 'Stack', 'Graveyard'])
            );
            if ($localInPlay) {
                $openingPhase = false;

                continue;
            }

            $localState = $players->first(fn ($p) => (int) $p['Id'] === $localInstanceId);
            if (! $localState) {
                continue;
            }

            $handCardsNow = $cards
                ->filter(fn ($c) => $c['Zone'] === 'Hand' && (int) $c['Owner'] === $localInstanceId)
                ->mapWithKeys(fn ($c) => [(int) $c['Id'] => (int) $c['CatalogID']])
                ->toArray();

            if (empty($handCardsNow) && empty($currentHandInstances)) {
                continue;
            }

            if (empty($currentHandInstances) && ! empty($handCardsNow)) {
                $currentHandInstances = $handCardsNow;

                continue;
            }

            if (! empty($handCardsNow)) {
                $currentIds = array_keys($currentHandInstances);
                $newIds = array_keys($handCardsNow);
                $overlap = array_intersect($currentIds, $newIds);

                if (empty($overlap) && count($newIds) >= 4) {
                    $mulliganedHands[] = array_values($currentHandInstances);
                    $currentHandInstances = $handCardsNow;
                } elseif (count($newIds) < count($currentIds)) {
                    $handBeforeBottoming = $currentHandInstances;
                    foreach (array_diff($currentIds, $newIds) as $removedId) {
                        $bottomedInstanceIds[] = $removedId;
                    }
                    $currentHandInstances = $handCardsNow;
                } else {
                    $currentHandInstances = $handCardsNow;
                }
            }
        }

        $toCard = fn ($catalogId, bool $bottomed = false) => [
            'name' => $cardsByMtgoId->get($catalogId)?->name ?? "Unknown ({$catalogId})",
            'image' => $cardsByMtgoId->get($catalogId)?->image,
            'bottomed' => $bottomed,
        ];

        $displayHand = ! empty($bottomedInstanceIds) ? $handBeforeBottoming : $currentHandInstances;
        $keptHand = [];
        foreach ($displayHand as $instanceId => $catalogId) {
            $keptHand[] = $toCard($catalogId, in_array($instanceId, $bottomedInstanceIds));
        }

        $mulliganedHandsFormatted = array_map(
            fn ($hand) => array_map(fn ($catalogId) => $toCard($catalogId), $hand),
            $mulliganedHands
        );

        $opponentMulligans = 0;
        if ($opponentStartLibrary !== null && $opponentFirstHandLibrary !== null) {
            $opponentMulligans = max(0, $opponentFirstHandLibrary - ($opponentStartLibrary - 7));
        }

        return [
            'localMulligans' => count($mulliganedHands),
            'opponentMulligans' => $opponentMulligans,
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
                    'name' => $card?->name ?? 'Unknown',
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
                    'name' => $card?->name ?? 'Unknown',
                    'image' => $card?->image,
                    'quantity' => $registeredQty - $gameQty,
                    'type' => 'out',
                ];
            }
        }

        return $changes;
    }
}
