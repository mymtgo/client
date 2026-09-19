<?php

namespace App\Dashboard\Widgets;

use App\Actions\Dashboard\GetPlayDrawSplit;
use App\Actions\Dashboard\GetStreak;
use App\Actions\Dashboard\GetWinrateDelta;
use App\Actions\Leagues\GetActiveLeague;
use App\Actions\Util\Winrate;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;
use App\Data\Front\MatchRecordData;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;

class KpiStripWidget implements WidgetType
{
    public function key(): string
    {
        return 'kpi_strip';
    }

    public function label(): string
    {
        return 'Overview';
    }

    public function span(): int
    {
        return 12;
    }

    public function maxColumns(): int
    {
        return 12;
    }

    public function allowsMultiple(): bool
    {
        return false;
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    /**
     * @return array{
     *     matchRecord: MatchRecordData,
     *     gamesWon: int,
     *     gamesLost: int,
     *     gameWinrate: int,
     *     streak: array<string, mixed>,
     *     matchWinrateDelta: int,
     *     gameWinrateDelta: int,
     *     playDrawSplit: array<string, mixed>,
     *     activeLeague: array<string, mixed>|null,
     * }
     */
    public function resolve(array $config, DashboardScope $scope): array
    {
        $stats = MtgoMatch::complete()
            ->when($scope->accountId, fn ($q, $id) => $q->forAccount($id))
            ->whereBetween('matches.started_at', [$scope->start, $scope->end])
            ->toBase()
            ->join('games', 'games.match_id', '=', 'matches.id')
            ->selectRaw("
                COUNT(DISTINCT matches.id) as total_matches,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'win' THEN matches.id END) as wins,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'loss' THEN matches.id END) as losses,
                SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as games_won,
                SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as games_lost
            ")
            ->first();

        $gamesWon = (int) ($stats->games_won ?? 0);
        $gamesLost = (int) ($stats->games_lost ?? 0);
        $deltas = GetWinrateDelta::run($scope->accountId, $scope->start, $scope->end, $scope->timeframe);

        return [
            'matchRecord' => MatchRecord::fromTotal(
                wins: (int) ($stats->wins ?? 0),
                losses: (int) ($stats->losses ?? 0),
                total: (int) ($stats->total_matches ?? 0),
            )->toData(),
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'streak' => GetStreak::run($scope->accountId, $scope->start, $scope->end),
            'matchWinrateDelta' => $deltas['matchDelta'],
            'gameWinrateDelta' => $deltas['gameDelta'],
            'playDrawSplit' => GetPlayDrawSplit::run($scope->accountId, $scope->start, $scope->end),
            'activeLeague' => GetActiveLeague::run(),
        ];
    }
}
