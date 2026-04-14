<?php

namespace App\Actions\Import;

use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReprocessImportedCardData
{
    /**
     * Re-extract card data from stored game logs for all imported matches,
     * update game_player.deck_json, and re-dispatch archetype detection.
     *
     * @return array{reprocessed: int, skipped: int}
     */
    public static function run(): array
    {
        $matches = MtgoMatch::query()
            ->where('imported', true)
            ->whereHas('games')
            ->with(['games.players'])
            ->get();

        $reprocessed = 0;
        $skipped = 0;

        foreach ($matches as $match) {
            $gameLog = GameLog::where('match_token', $match->token)
                ->whereNotNull('decoded_entries')
                ->first();

            if (! $gameLog) {
                $skipped++;

                continue;
            }

            try {
                self::reprocessMatch($match, $gameLog);
                $reprocessed++;
            } catch (\Throwable $e) {
                Log::warning("Failed to reprocess match {$match->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        return ['reprocessed' => $reprocessed, 'skipped' => $skipped];
    }

    private static function reprocessMatch(MtgoMatch $match, GameLog $gameLog): void
    {
        $cardData = ExtractCardsFromGameLog::run($gameLog->decoded_entries);
        $players = $cardData['players'];

        if (count($players) < 2) {
            return;
        }

        $firstGame = $match->games->first();

        if (! $firstGame) {
            return;
        }

        $localPlayer = $firstGame->players->first(fn ($p) => $p->pivot->is_local);
        $opponentPlayer = $firstGame->players->first(fn ($p) => ! $p->pivot->is_local);

        if (! $localPlayer || ! $opponentPlayer) {
            return;
        }

        $localName = $localPlayer->username;
        $opponentName = $opponentPlayer->username;

        // Hydrate all extracted cards so oracle_ids are available
        $allCards = collect($cardData['cards_by_player'][$localName] ?? [])
            ->merge($cardData['cards_by_player'][$opponentName] ?? [])
            ->map(fn ($c) => ['mtgo_id' => $c['mtgo_id'], 'name' => $c['name']])
            ->unique('mtgo_id')
            ->values()
            ->toArray();

        if (! empty($allCards)) {
            ImportMatches::hydrateCards($allCards);
        }

        $buildDeckJson = fn ($cards) => ! empty($cards)
            ? collect($cards)->map(fn ($card) => [
                'mtgo_id' => $card['mtgo_id'],
                'quantity' => 1,
                'sideboard' => false,
            ])->values()->toArray()
            : null;

        $cardsByGame = $cardData['cards_by_game'] ?? [];
        $gameMeta = $cardData['game_meta'] ?? [];

        foreach ($match->games as $index => $game) {
            $gameCards = $cardsByGame[$index] ?? [];
            $localDeckJson = $buildDeckJson($gameCards[$localName] ?? []);
            $opponentDeckJson = $buildDeckJson($gameCards[$opponentName] ?? []);

            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('player_id', $localPlayer->id)
                ->update(['deck_json' => json_encode($localDeckJson)]);

            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('player_id', $opponentPlayer->id)
                ->update(['deck_json' => json_encode($opponentDeckJson)]);

            $meta = $gameMeta[$index] ?? [];
            if (! empty($meta['turn_count'])) {
                $game->update(['turn_count' => $meta['turn_count']]);
            }

            if (! empty($meta['dice_rolls'])) {
                DB::table('game_player')
                    ->where('game_id', $game->id)
                    ->where('player_id', $localPlayer->id)
                    ->update([
                        'dice_roll' => $meta['dice_rolls'][$localName] ?? null,
                        'mulligan_count' => $meta['mulligans'][$localName] ?? 0,
                    ]);

                DB::table('game_player')
                    ->where('game_id', $game->id)
                    ->where('player_id', $opponentPlayer->id)
                    ->update([
                        'dice_roll' => $meta['dice_rolls'][$opponentName] ?? null,
                        'mulligan_count' => $meta['mulligans'][$opponentName] ?? 0,
                    ]);
            }
        }

        DetermineMatchArchetypesJob::dispatch($match->id)->onQueue('match_archetypes');
    }
}
