<?php

namespace App\Actions\Games;

use App\Actions\Matches\RecomputeManualMatchStats;
use App\Models\Game;

class UpdateManualGameSideboard
{
    /**
     * Write the full postboard list to the local pivot so the existing diff
     * and stats logic treat the game like a tracked one.
     *
     * @param  list<array{mtgo_id: int, quantity: int, type: string}>  $changes
     * @return bool true when a stored opening hand no longer fit and was cleared
     */
    public static function run(Game $game, array $changes): bool
    {
        $game->loadMissing('players', 'match');

        $local = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $local) {
            return false;
        }

        $deckJson = $changes === [] ? [] : self::buildDeckJson($game, $changes);

        $payload = ['deck_json' => $deckJson];
        $handCleared = false;

        $openingHand = $local->pivot->opening_hand_json;
        if ($openingHand !== null && ! self::openingHandFits($openingHand, $deckJson === [] ? ResolveRegisteredDeckQuantities::run($game)[0] : self::mainsFromDeckJson($deckJson))) {
            $payload += ['opening_hand_json' => null, 'mulligan_count' => 0, 'starting_hand_size' => 7];
            $handCleared = true;
        }

        $game->players()->updateExistingPivot($local->id, $payload);

        RecomputeManualMatchStats::run($game->match);

        return $handCleared;
    }

    /**
     * @param  list<array{mtgo_id: int, quantity: int, type: string}>  $changes
     * @return list<array{mtgo_id: int, quantity: int, sideboard: bool}>
     */
    private static function buildDeckJson(Game $game, array $changes): array
    {
        [$mains, $sideboard] = ResolveRegisteredDeckQuantities::run($game);

        foreach ($changes as $change) {
            $mtgoId = (int) $change['mtgo_id'];
            $qty = (int) $change['quantity'];

            if ($change['type'] === 'out') {
                $mains[$mtgoId] = ($mains[$mtgoId] ?? 0) - $qty;
                $sideboard[$mtgoId] = ($sideboard[$mtgoId] ?? 0) + $qty;
            } else {
                $sideboard[$mtgoId] = ($sideboard[$mtgoId] ?? 0) - $qty;
                $mains[$mtgoId] = ($mains[$mtgoId] ?? 0) + $qty;
            }
        }

        $rows = [];
        foreach ($mains as $mtgoId => $qty) {
            if ($qty > 0) {
                $rows[] = ['mtgo_id' => $mtgoId, 'quantity' => $qty, 'sideboard' => false];
            }
        }
        foreach ($sideboard as $mtgoId => $qty) {
            if ($qty > 0) {
                $rows[] = ['mtgo_id' => $mtgoId, 'quantity' => $qty, 'sideboard' => true];
            }
        }

        return $rows;
    }

    /**
     * Every recorded hand (final, bottomed and each mulligan) must still be
     * drawable from the new maindeck.
     *
     * @param  array{kept?: array<int, int>, bottomed?: array<int, int>, mulligans?: array<int, array<int, int>>}  $openingHand
     * @param  array<int, int>  $mains  mtgo id => copies
     */
    private static function openingHandFits(array $openingHand, array $mains): bool
    {
        $hands = [
            [...($openingHand['kept'] ?? []), ...($openingHand['bottomed'] ?? [])],
            ...($openingHand['mulligans'] ?? []),
        ];

        foreach ($hands as $hand) {
            $fits = collect(array_count_values(array_map('intval', $hand)))
                ->every(fn ($count, $mtgoId) => ($mains[$mtgoId] ?? 0) >= $count);

            if (! $fits) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{mtgo_id: int, quantity: int, sideboard: bool}>  $deckJson
     * @return array<int, int> mtgo id => copies
     */
    private static function mainsFromDeckJson(array $deckJson): array
    {
        return collect($deckJson)->where('sideboard', false)->pluck('quantity', 'mtgo_id')->all();
    }
}
