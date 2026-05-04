<?php

namespace App\Actions\Cards;

class AggregateGameLogCardStats
{
    /**
     * Aggregate card stats from a parsed game-log payload, scoped to one player and one game index.
     *
     * @param  array<string, mixed>  $gameLogStats  output of ExtractCardsFromGameLog::run
     * @param  array<string, string>  $catalogToOracle  CatalogID (string) => oracle_id
     * @return array{
     *     cast: array<string, int>,
     *     played: array<string, int>,
     *     kicked: array<string, int>,
     *     flashback: array<string, int>,
     *     madness: array<string, int>,
     *     evoked: array<string, int>,
     *     activated: array<string, int>,
     *     pregame_revealed: array<string, true>,
     *     pregame_played: array<string, true>
     * }
     */
    public static function run(array $gameLogStats, int $gameIndex, string $playerName, array $catalogToOracle): array
    {
        $cast = [];
        $played = [];
        $kicked = [];
        $flashback = [];
        $madness = [];
        $evoked = [];
        $activated = [];
        $pregameRevealed = [];
        $pregamePlayed = [];

        $gameCards = $gameLogStats['cards_by_game'][$gameIndex][$playerName] ?? [];

        foreach ($gameCards as $card) {
            $oracleId = $catalogToOracle[(string) $card['mtgo_id']] ?? null;
            if (! $oracleId) {
                continue;
            }
            $cast[$oracleId] = ($cast[$oracleId] ?? 0) + $card['cast'];
            $played[$oracleId] = ($played[$oracleId] ?? 0) + $card['played'];
            $kicked[$oracleId] = ($kicked[$oracleId] ?? 0) + $card['kicked'];
            $flashback[$oracleId] = ($flashback[$oracleId] ?? 0) + $card['flashback'];
            $madness[$oracleId] = ($madness[$oracleId] ?? 0) + $card['madness'];
            $evoked[$oracleId] = ($evoked[$oracleId] ?? 0) + $card['evoked'];
            $activated[$oracleId] = ($activated[$oracleId] ?? 0) + $card['activated'];
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
            'cast' => $cast,
            'played' => $played,
            'kicked' => $kicked,
            'flashback' => $flashback,
            'madness' => $madness,
            'evoked' => $evoked,
            'activated' => $activated,
            'pregame_revealed' => $pregameRevealed,
            'pregame_played' => $pregamePlayed,
        ];
    }
}
