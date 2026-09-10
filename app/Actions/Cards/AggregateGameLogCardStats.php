<?php

namespace App\Actions\Cards;

use App\Actions\Import\ExtractCardsFromGameLog;

class AggregateGameLogCardStats
{
    /**
     * Aggregate card stats from a parsed game-log payload, scoped to one player and one game index.
     *
     * Buckets every counter in ExtractCardsFromGameLog::COUNTER_FIELDS by oracle id,
     * plus pregame reveal/play flags.
     *
     * @param  array<string, mixed>  $gameLogStats  output of ExtractCardsFromGameLog::run
     * @param  array<string, string>  $catalogToOracle  CatalogID (string) => oracle_id
     * @return array<string, array<string, int|true>>
     */
    public static function run(array $gameLogStats, int $gameIndex, string $playerName, array $catalogToOracle): array
    {
        $stats = array_fill_keys(ExtractCardsFromGameLog::COUNTER_FIELDS, []);
        $pregameRevealed = [];
        $pregamePlayed = [];

        // Earliest cast across the copies of a card: the question is how soon
        // the deck got one down, not what the fourth copy did on turn nine.
        $castTurn = [];

        $gameCards = $gameLogStats['cards_by_game'][$gameIndex][$playerName] ?? [];

        foreach ($gameCards as $card) {
            $oracleId = $catalogToOracle[(string) $card['mtgo_id']] ?? null;
            if (! $oracleId) {
                continue;
            }
            foreach (ExtractCardsFromGameLog::COUNTER_FIELDS as $field) {
                $stats[$field][$oracleId] = ($stats[$field][$oracleId] ?? 0) + ($card[$field] ?? 0);
            }

            $turn = $card['cast_turn'] ?? null;

            if ($turn !== null) {
                $castTurn[$oracleId] = min($castTurn[$oracleId] ?? $turn, $turn);
            }
        }

        $pregameActions = $gameLogStats['pregame_actions'][$gameIndex][$playerName] ?? [];

        foreach ($pregameActions as $action) {
            $oracleId = $catalogToOracle[(string) $action['mtgo_id']] ?? null;
            if (! $oracleId) {
                continue;
            }
            if ($action['type'] === 'revealed') {
                $pregameRevealed[$oracleId] = true;
            }
            if ($action['type'] === 'played') {
                $pregamePlayed[$oracleId] = true;
            }
        }

        return [
            ...$stats,
            'cast_turn' => $castTurn,
            'pregame_revealed' => $pregameRevealed,
            'pregame_played' => $pregamePlayed,
        ];
    }
}
