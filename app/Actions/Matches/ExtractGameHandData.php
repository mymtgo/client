<?php

namespace App\Actions\Matches;

use App\Models\Game;

class ExtractGameHandData
{
    /**
     * Extract hand data from a game for API reporting.
     *
     * Returns raw mulligan/hand data with catalog IDs (no display formatting).
     * Algorithm mirrors ShowController::parseHandData() but returns only what the API needs.
     *
     * @return array{mulligan_count: int, starting_hand_size: int, kept_hand: int[], opponent_mulligan_count: int, on_play: bool, won: bool}
     */
    public static function run(Game $game): array
    {
        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);
        $opponentPlayer = $game->players->first(fn ($p) => ! $p->pivot->is_local);

        $localInstanceId = (int) ($localPlayer?->pivot->instance_id ?? 1);
        $opponentInstanceId = (int) ($opponentPlayer?->pivot->instance_id ?? 0);

        $snapshots = $game->timeline->sortBy('timestamp');

        $mulliganCount = 0;
        $currentHandInstances = []; // [instanceId => catalogId]
        $bottomedInstanceIds = [];
        $openingPhase = true;

        // Opponent mulligan tracking via library count
        $opponentStartLibrary = null;
        $opponentFirstHandLibrary = null;

        foreach ($snapshots as $snapshot) {
            $content = $snapshot->content;
            $players = collect($content['Players'] ?? []);
            $cards = collect($content['Cards'] ?? []);

            // --- Opponent mulligan detection (library-count based) ---
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

            // --- End-of-opening-phase detection ---
            $localInPlay = $cards->first(fn ($c) => (int) $c['Owner'] === $localInstanceId &&
                in_array($c['Zone'], ['Battlefield', 'Stack', 'Graveyard'])
            );
            if ($localInPlay) {
                $openingPhase = false;

                continue;
            }

            // --- Local player hand tracking ---
            $localState = $players->first(fn ($p) => (int) $p['Id'] === $localInstanceId);
            if (! $localState) {
                continue;
            }

            $handCardsNow = $cards
                ->filter(fn ($c) => $c['Zone'] === 'Hand' && (int) $c['Owner'] === $localInstanceId)
                ->mapWithKeys(fn ($c) => [(int) $c['Id'] => (int) $c['CatalogID']])
                ->toArray();

            if (empty($handCardsNow) && empty($currentHandInstances)) {
                continue; // Pre-draw
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
                    // Complete replacement → mulligan
                    $mulliganCount++;
                    $currentHandInstances = $handCardsNow;
                } elseif (count($newIds) < count($currentIds)) {
                    // Hand shrank → card(s) bottomed
                    foreach (array_diff($currentIds, $newIds) as $removedId) {
                        $bottomedInstanceIds[] = $removedId;
                    }
                    $currentHandInstances = $handCardsNow;
                } else {
                    $currentHandInstances = $handCardsNow;
                }
            }
        }

        // Opponent mulligans: library after first hand > (startLibrary - 7) means shuffling back
        $opponentMulliganCount = 0;
        if ($opponentStartLibrary !== null && $opponentFirstHandLibrary !== null) {
            $opponentMulliganCount = max(0, $opponentFirstHandLibrary - ($opponentStartLibrary - 7));
        }

        // Kept hand = current hand instances (catalog IDs), excluding bottomed cards
        $keptHand = array_values($currentHandInstances);

        return [
            'mulligan_count' => $mulliganCount,
            'starting_hand_size' => count($keptHand),
            'kept_hand' => $keptHand,
            'opponent_mulligan_count' => $opponentMulliganCount,
            'on_play' => (bool) ($localPlayer?->pivot->on_play ?? false),
            'won' => (bool) $game->won,
        ];
    }
}
