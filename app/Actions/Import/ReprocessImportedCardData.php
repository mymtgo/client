<?php

namespace App\Actions\Import;

use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\GameDeck;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ReprocessImportedCardData
{
    /**
     * Re-extract card data from stored game logs for all imported matches,
     * update game_decks.deck_json, and re-dispatch archetype detection.
     *
     * @return array{reprocessed: int, skipped: int}
     */
    public static function run(): array
    {
        $matches = MtgoMatch::query()
            ->where('imported', true)
            ->whereHas('games')
            ->with(['games.decks'])
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

        // Resolve local and opponent names from the match's participant columns.
        // Fall back to inspecting the first game's decks if account/opponent aren't set.
        $localName = $match->account?->username;
        $opponentName = $match->opponent?->username;

        // If match-level identity is missing, skip — we can't identify who is local.
        if (! $localName || ! $opponentName) {
            return;
        }

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

            GameDeck::updateOrCreate(
                ['game_id' => $game->id, 'is_opponent' => false],
                ['deck_json' => $localDeckJson],
            );

            GameDeck::updateOrCreate(
                ['game_id' => $game->id, 'is_opponent' => true],
                ['deck_json' => $opponentDeckJson],
            );

            $meta = $gameMeta[$index] ?? [];
            $gameUpdates = [];

            if (! empty($meta['turn_count'])) {
                $gameUpdates['turn_count'] = $meta['turn_count'];
            }

            if (! empty($meta['dice_rolls'])) {
                $localDice = $meta['dice_rolls'][$localName] ?? null;
                $oppDice = $meta['dice_rolls'][$opponentName] ?? null;

                if ($localDice !== null) {
                    $gameUpdates['local_dice'] = $localDice;
                }
                if ($oppDice !== null) {
                    $gameUpdates['opp_dice'] = $oppDice;
                }
            }

            if (! empty($meta['mulligans'])) {
                $localMulligans = $meta['mulligans'][$localName] ?? null;
                $oppMulligans = $meta['mulligans'][$opponentName] ?? null;

                if ($localMulligans !== null) {
                    $gameUpdates['local_mulligans'] = $localMulligans;
                }
                if ($oppMulligans !== null) {
                    $gameUpdates['opp_mulligans'] = $oppMulligans;
                }
            }

            if (! empty($gameUpdates)) {
                $game->update($gameUpdates);
            }
        }

        DetermineMatchArchetypesJob::dispatch($match->id)->onQueue('match_archetypes');
    }
}
