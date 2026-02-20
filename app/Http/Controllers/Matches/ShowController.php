<?php

namespace App\Http\Controllers\Matches;

use App\Data\Front\ArchetypeData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $match = MtgoMatch::with([
            'games.players',
            'games.timeline',
            'opponentArchetypes.archetype',
            'opponentArchetypes.player',
            'deck',
            'league',
        ])->find($id);

        if (! $match) {
            return redirect()->route('home');
        }

        $deckVersion = DeckVersion::find($match->deck_version_id);
        $registeredCards = $deckVersion?->cards ?? [];

        // Batch all mtgo_ids across every game player deck_json
        $allMtgoIds = $match->games->flatMap(fn ($game) => $game->players->flatMap(
            fn ($player) => collect($player->pivot->deck_json)->pluck('mtgo_id')
        ))->unique();

        dd($match->games->pluck('id'));

        $cardsByMtgoId = Card::whereIn('mtgo_id', $allMtgoIds)->get()->keyBy('mtgo_id');

        // Also need names for registered deck cards (keyed by oracle_id)
        $registeredOracleIds = collect($registeredCards)->pluck('oracle_id')->filter()->unique();
        $cardsByOracleId = Card::whereIn('oracle_id', $registeredOracleIds)->get()->keyBy('oracle_id');

        $games = $match->games
            ->sortBy('started_at')
            ->values()
            ->map(fn ($game, $index) => $this->buildGameData(
                $game, $index + 1, $cardsByMtgoId, $cardsByOracleId, $registeredCards
            ));

        return Inertia::render('matches/Show', [
            'match' => MatchData::from($match),
            'games' => $games,
            'archetypes' => ArchetypeData::collect(Archetype::orderBy('name')->get()),
        ]);
    }

    private function buildGameData(Game $game, int $number, Collection $cardsByMtgoId, Collection $cardsByOracleId, array $registeredCards): array
    {
        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);
        $opponentPlayer = $game->players->first(fn ($p) => ! $p->pivot->is_local);

        $localInstanceId = (int) ($localPlayer?->pivot->instance_id ?? 1);
        $opponentInstanceId = (int) ($opponentPlayer?->pivot->instance_id ?? 0);

        $handData = $this->parseHandData($game, $localInstanceId, $opponentInstanceId, $cardsByMtgoId);

        $opponentCardsSeen = collect($opponentPlayer?->pivot->deck_json ?? [])
            ->map(fn ($item) => [
                'name' => $cardsByMtgoId->get($item['mtgo_id'])?->name ?? "Unknown ({$item['mtgo_id']})",
                'image' => $cardsByMtgoId->get($item['mtgo_id'])?->image,
            ])
            ->unique('name')
            ->values()
            ->toArray();

        $sideboardChanges = $this->computeSideboardChanges(
            $localPlayer?->pivot->deck_json ?? [],
            $registeredCards,
            $cardsByMtgoId,
            $cardsByOracleId,
        );

        $localCardsPlayed = $this->parseLocalCardsPlayed($game, $localInstanceId, $cardsByMtgoId);

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
     *
     * Each timeline entry is a full game-state snapshot. We track:
     * - Complete hand replacements (all instance IDs change) → mulligan
     * - Hand shrinks while library grows → card bottomed
     * - Stop once a local card enters Battlefield or Stack → game in progress
     */
    private function parseHandData(Game $game, int $localInstanceId, int $opponentInstanceId, Collection $cardsByMtgoId): array
    {
        $snapshots = $game->timeline->sortBy('timestamp');

        $mulliganedHands = [];
        $currentHandInstances = []; // [instanceId => catalogId]
        $handBeforeBottoming = []; // preserved for display: the 7-card hand before a bottom
        $bottomedInstanceIds = []; // instance IDs of cards put back to library
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
                // We only need opponent's first-hand library to compute mulligans
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
                    $mulliganedHands[] = array_values($currentHandInstances);
                    $currentHandInstances = $handCardsNow;
                } elseif (count($newIds) < count($currentIds)) {
                    // Hand shrank → card(s) bottomed; preserve the full hand for display
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

        // Show the full hand before bottoming (7 cards) with the bottomed card marked,
        // or just the kept hand if no bottoming occurred.
        $displayHand = ! empty($bottomedInstanceIds) ? $handBeforeBottoming : $currentHandInstances;
        $keptHand = [];
        foreach ($displayHand as $instanceId => $catalogId) {
            $keptHand[] = $toCard($catalogId, in_array($instanceId, $bottomedInstanceIds));
        }

        $mulliganedHandsFormatted = array_map(
            fn ($hand) => array_map(fn ($catalogId) => $toCard($catalogId), $hand),
            $mulliganedHands
        );

        // Opponent mulligans: library after first hand > (startLibrary - 7) means bottoming happened
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
     *
     * Scans all timeline snapshots for cards owned by the local player that appeared
     * in Battlefield, Stack, or Graveyard zones — i.e. cards they actually cast or played,
     * not just held in hand or kept in library.
     */
    private function parseLocalCardsPlayed(Game $game, int $localInstanceId, Collection $cardsByMtgoId): array
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
                'name' => $cardsByMtgoId->get($catalogId)?->name ?? "Unknown ({$catalogId})",
                'image' => $cardsByMtgoId->get($catalogId)?->image,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Compute sideboard changes relative to the registered deck version.
     *
     * Compares maindeck card counts: cards with more copies in the game deck than the
     * registered deck were "brought in"; cards with fewer copies were "taken out".
     */
    private function computeSideboardChanges(array $gameDeckJson, array $registeredCards, Collection $cardsByMtgoId, Collection $cardsByOracleId): array
    {
        if (empty($gameDeckJson) || empty($registeredCards)) {
            return [];
        }

        // Game maindeck: oracleId → quantity
        $gameMains = [];
        foreach ($gameDeckJson as $item) {
            if (! ($item['sideboard'] ?? false)) {
                $oracleId = $cardsByMtgoId->get($item['mtgo_id'])?->oracle_id ?? "mtgo_{$item['mtgo_id']}";
                $gameMains[$oracleId] = ($gameMains[$oracleId] ?? 0) + (int) ($item['quantity'] ?? 1);
            }
        }

        // Registered maindeck: oracleId → quantity (sideboard field is string 'true'/'false')
        $registeredMains = [];
        foreach ($registeredCards as $item) {
            if (($item['sideboard'] ?? 'false') === 'false') {
                $registeredMains[$item['oracle_id']] = (int) $item['quantity'];
            }
        }

        $changes = [];

        // Cards with MORE copies in game maindeck → brought in from sideboard
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

        // Cards with FEWER copies in game maindeck → taken out to sideboard
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
