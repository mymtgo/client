<?php

namespace App\Actions\Import;

use App\Actions\Cards\CreateMissingCards;
use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameHistory;
use App\Facades\Mtgo;
use App\Models\MtgoMatch;

class ParseImportableMatches
{
    /**
     * Parse MTGO history + game logs and return importable match data.
     *
     * @param  string|null  $dataPath  Override data path (for dev/testing)
     * @return array<int, array>
     */
    public static function run(?string $dataPath = null): array
    {
        $dataPath ??= Mtgo::getLogDataPath();
        $historyPath = ParseGameHistory::findFile() ?? $dataPath.'/mtgo_game_history';
        $historyRecords = ParseGameHistory::parse($historyPath);

        if (empty($historyRecords)) {
            return [];
        }

        // Filter out matches already in DB
        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        );

        if (empty($newRecords)) {
            return [];
        }

        // Match history records to game log files
        $logMatches = MatchGameLogToHistory::run($newRecords, $dataPath);
        $logMatchesByHistoryId = collect($logMatches)->keyBy('history_id');

        // Extract cards from all matched game logs and collect unique mtgo_ids
        $allMtgoIds = [];
        $cardDataByHistoryId = [];

        foreach ($logMatches as $logMatch) {
            if ($logMatch['game_log_entries'] === null) {
                continue;
            }

            $cardData = ExtractCardsFromGameLog::run($logMatch['game_log_entries']);
            $cardDataByHistoryId[$logMatch['history_id']] = $cardData;

            foreach ($cardData['cards_by_player'] as $cards) {
                foreach ($cards as $card) {
                    $allMtgoIds[$card['mtgo_id']] = true;
                }
            }
        }

        // Create missing card stubs
        if (! empty($allMtgoIds)) {
            CreateMissingCards::run(array_keys($allMtgoIds));
        }

        // Build importable match array
        $results = [];

        foreach ($newRecords as $record) {
            $logMatch = $logMatchesByHistoryId->get($record['Id']);
            $hasGameLog = $logMatch && $logMatch['game_log_token'] !== null;
            $cardData = $cardDataByHistoryId[$record['Id']] ?? null;

            $localPlayer = null;
            $localCards = null;
            $opponentCards = null;
            $games = null;

            if ($hasGameLog && $cardData) {
                $opponent = $record['Opponents'][0] ?? null;
                $players = $cardData['players'];

                // Determine local player (the one who isn't the opponent)
                $localPlayer = collect($players)->first(fn ($p) => $p !== $opponent) ?? $players[0] ?? null;

                $localCards = $cardData['cards_by_player'][$localPlayer] ?? [];
                $opponentCards = $cardData['cards_by_player'][$opponent] ?? [];

                // Extract per-game results
                $gameResults = ExtractGameResults::run(
                    $logMatch['game_log_entries'],
                    $localPlayer
                );

                $games = collect($gameResults['games'])->map(fn ($g) => [
                    'game_index' => $g['game_index'],
                    'won' => $g['winner'] === $localPlayer,
                    'on_play' => $g['on_play'] === $localPlayer,
                    'starting_hand_size' => $g['starting_hands'][$localPlayer] ?? 7,
                    'opponent_hand_size' => $g['starting_hands'][$opponent] ?? 7,
                    'started_at' => $g['started_at'],
                    'ended_at' => $g['ended_at'],
                ])->toArray();
            }

            // Attempt deck matching
            $deckSuggestion = ($localCards && ! empty($localCards))
                ? SuggestDeckForMatch::run($localCards)
                : null;

            $wins = $record['GameWins'];
            $losses = $record['GameLosses'];
            $outcome = $wins > $losses ? 'win' : ($wins < $losses ? 'loss' : 'draw');

            $results[] = [
                'history_id' => $record['Id'],
                'started_at' => $record['StartTime'],
                'opponent' => $record['Opponents'][0] ?? 'Unknown',
                'format' => MtgoMatch::displayFormat($record['Format'] ?? ''),
                'format_raw' => $record['Format'] ?? '',
                'games_won' => $wins,
                'games_lost' => $losses,
                'outcome' => $outcome,
                'round' => $record['Round'] ?? 0,
                'description' => $record['Description'] ?? '',
                'has_game_log' => $hasGameLog,
                'game_log_token' => $logMatch['game_log_token'] ?? null,
                'games' => $games,
                'local_player' => $localPlayer,
                'local_cards' => $localCards,
                'opponent_cards' => $opponentCards,
                'suggested_deck_version_id' => $deckSuggestion['deck_version_id'] ?? null,
                'suggested_deck_name' => $deckSuggestion['deck_name'] ?? null,
                'deck_match_confidence' => $deckSuggestion['confidence'] ?? null,
                'deck_deleted' => $deckSuggestion['deck_deleted'] ?? false,
                'game_ids' => $record['GameIds'] ?? [],
            ];
        }

        return $results;
    }
}
