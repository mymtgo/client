<?php

namespace App\Actions\Matches;

use App\Models\Game;

class ParseOpeningHand
{
    /**
     * Parse opening hand data from a game's timeline snapshots.
     *
     * Runs the mulligan/bottoming state machine and returns raw structural
     * data (instance IDs and catalog IDs). Callers are responsible for
     * formatting the output for their context (API vs display).
     *
     * @return array{
     *   mulliganed_hands: array<int, array<int, int>>,
     *   kept_hand: array<int, int>,
     *   bottomed_instance_ids: int[],
     *   hand_before_bottoming: array<int, int>,
     *   opponent_mulligans: int,
     * }
     */
    public static function run(Game $game, int $localInstanceId, int $opponentInstanceId): array
    {
        $snapshots = $game->timeline->sortBy('timestamp');

        $mulliganedHands = [];       // Each entry: [instanceId => catalogId]
        $currentHandInstances = [];  // [instanceId => catalogId]
        $latestFullHand = [];        // Largest hand seen since last mulligan, used to derive bottoms
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

            if (empty($currentHandInstances)) {
                $currentHandInstances = $handCardsNow;
                $latestFullHand = $handCardsNow;

                continue;
            }

            if (! empty($handCardsNow)) {
                $currentIds = array_keys($currentHandInstances);
                $newIds = array_keys($handCardsNow);
                $overlap = array_intersect($currentIds, $newIds);

                if (empty($overlap) && count($newIds) >= 4) {
                    // Complete replacement → mulligan. Reset full-hand watermark.
                    $mulliganedHands[] = $currentHandInstances;
                    $currentHandInstances = $handCardsNow;
                    $latestFullHand = $handCardsNow;
                } else {
                    $currentHandInstances = $handCardsNow;
                    // Track the largest hand seen since the last mulligan. Bottoming
                    // selection swaps can shrink the hand transiently (e.g. 7→6→5→6);
                    // the high-water mark plus union of any cards that come back gives
                    // the true pre-bottom hand.
                    foreach ($handCardsNow as $instanceId => $catalogId) {
                        if (! array_key_exists($instanceId, $latestFullHand)) {
                            $latestFullHand[$instanceId] = $catalogId;
                        }
                    }
                }
            }
        }

        // Derive bottomed cards by diffing the pre-bottom high-water hand against the
        // settled hand at end of opening phase. Robust to transient mid-bottoming
        // snapshots that briefly remove and re-add cards as the user swaps selection.
        $bottomedInstanceIds = array_values(array_diff(
            array_keys($latestFullHand),
            array_keys($currentHandInstances)
        ));
        $handBeforeBottoming = ! empty($bottomedInstanceIds) ? $latestFullHand : [];

        // Opponent mulligans: library after first hand > (startLibrary - 7) means shuffled back
        $opponentMulligans = 0;
        if ($opponentStartLibrary !== null && $opponentFirstHandLibrary !== null) {
            $opponentMulligans = max(0, $opponentFirstHandLibrary - ($opponentStartLibrary - 7));
        }

        return [
            'mulliganed_hands' => $mulliganedHands,
            'kept_hand' => $currentHandInstances,
            'bottomed_instance_ids' => $bottomedInstanceIds,
            'hand_before_bottoming' => $handBeforeBottoming,
            'opponent_mulligans' => $opponentMulligans,
        ];
    }
}
