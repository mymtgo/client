<?php

namespace App\Jobs;

use App\Actions\Util\ExtractJson;
use App\Models\Game;
use App\Models\GameTimeline;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillDeckJsonAndCardStats implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $matchIds = MtgoMatch::query()
            ->where('state', 'complete')
            ->whereNotNull('deck_version_id')
            ->pluck('id');

        $updatedGames = 0;

        foreach ($matchIds as $matchId) {
            $updatedGames += $this->rebuildDeckJsonForMatch($matchId);
        }

        Log::info("BackfillDeckJsonAndCardStats: updated deck_json for {$updatedGames} games across {$matchIds->count()} matches");

        // Wipe and recompute all card_game_stats with corrected deck_json
        DB::table('card_game_stats')->delete();

        foreach ($matchIds as $matchId) {
            ComputeCardGameStats::dispatch($matchId);
        }

        Log::info("BackfillDeckJsonAndCardStats: recomputed card stats for {$matchIds->count()} matches");
    }

    private function rebuildDeckJsonForMatch(int $matchId): int
    {
        $match = MtgoMatch::with('games')->find($matchId);

        if (! $match) {
            return 0;
        }

        $games = $match->games->sortBy('started_at')->values();
        $gameIds = $games->pluck('mtgo_id')->toArray();

        // Fetch DeckUsedInGame events for all games in this match
        $deckEvents = LogEvent::where('event_type', 'deck_used')
            ->whereIn('game_id', $gameIds)
            ->get()
            ->keyBy('game_id');

        $updated = 0;

        foreach ($games as $game) {
            if ($this->rebuildDeckJsonForGame($game, $deckEvents)) {
                $updated++;
            }
        }

        return $updated;
    }

    private function rebuildDeckJsonForGame(Game $game, $deckEvents): bool
    {
        $game->loadMissing('players');

        $localPlayer = $game->players->first(fn ($p) => $p->pivot->is_local);

        if (! $localPlayer) {
            return false;
        }

        $deckEvent = $deckEvents->get($game->mtgo_id);

        if (! $deckEvent) {
            return false;
        }

        $playerDeck = ExtractJson::run($deckEvent->raw_text)->first() ?: [];

        if (empty($playerDeck)) {
            return false;
        }

        // Build total quantities per CatalogId from DeckUsedInGame
        $totalQuantities = [];
        foreach ($playerDeck as $card) {
            $catalogId = $card['CatalogId'];
            $totalQuantities[$catalogId] = ($totalQuantities[$catalogId] ?? 0) + $card['Quantity'];
        }

        // Get first timeline snapshot for this game
        $firstSnapshot = GameTimeline::where('game_id', $game->id)
            ->orderBy('id')
            ->first();

        if (! $firstSnapshot) {
            return false;
        }

        $content = $firstSnapshot->content;
        $instanceId = (int) $localPlayer->pivot->instance_id;

        // Count actual sideboard cards from first snapshot
        $sideboardCounts = [];
        foreach ($content['Cards'] ?? [] as $snapshotCard) {
            if ((int) $snapshotCard['Owner'] === $instanceId && ($snapshotCard['Zone'] ?? '') === 'Sideboard') {
                $catalogId = $snapshotCard['CatalogID'];
                $sideboardCounts[$catalogId] = ($sideboardCounts[$catalogId] ?? 0) + 1;
            }
        }

        // Build corrected deck_json
        $deck = [];
        foreach ($totalQuantities as $catalogId => $total) {
            $sbQty = $sideboardCounts[$catalogId] ?? 0;
            $mbQty = $total - $sbQty;

            if ($mbQty > 0) {
                $deck[] = ['mtgo_id' => $catalogId, 'quantity' => $mbQty, 'sideboard' => false];
            }
            if ($sbQty > 0) {
                $deck[] = ['mtgo_id' => $catalogId, 'quantity' => $sbQty, 'sideboard' => true];
            }
        }

        // Update the pivot directly
        DB::table('game_player')
            ->where('game_id', $game->id)
            ->where('player_id', $localPlayer->id)
            ->update(['deck_json' => json_encode($deck)]);

        return true;
    }
}
