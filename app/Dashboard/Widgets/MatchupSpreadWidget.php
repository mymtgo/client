<?php

namespace App\Dashboard\Widgets;

use App\Actions\Dashboard\GetDashboardMatchupSpread;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;

class MatchupSpreadWidget implements WidgetType
{
    public function key(): string
    {
        return 'matchup_spread';
    }

    public function label(): string
    {
        return 'Top matchups';
    }

    public function span(): int
    {
        return 6;
    }

    public function maxColumns(): int
    {
        return 8;
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

    /** @return array<int, mixed> */
    public function resolve(array $config, DashboardScope $scope): array
    {
        return GetDashboardMatchupSpread::run($scope->accountId, $scope->start, $scope->end);
    }
}
