<?php

namespace App\Dashboard\Widgets;

use App\Actions\Dashboard\GetDashboardLeagueDistribution;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetType;
use App\Support\MtgoFormat;

class LeagueResultsWidget implements WidgetType
{
    public function key(): string
    {
        return 'league_results';
    }

    public function label(): string
    {
        return 'League results';
    }

    public function span(): int
    {
        return 4;
    }

    public function maxColumns(): int
    {
        return 6;
    }

    public function allowsMultiple(): bool
    {
        return false;
    }

    public function defaultConfig(): array
    {
        return ['format' => null];
    }

    public function validateConfig(array $config): array
    {
        $format = $config['format'] ?? null;

        if ($format !== null && ! is_string($format)) {
            throw new InvalidWidgetConfig('Format must be text.');
        }

        $format = $format === null ? null : trim($format);

        return ['format' => $format === '' ? null : $format];
    }

    /** @return array<string, mixed> */
    public function resolve(array $config, DashboardScope $scope): array
    {
        $distribution = GetDashboardLeagueDistribution::run($scope->accountId, $scope->start, $scope->end, $config['format']);

        return $distribution + [
            'formatLabel' => $config['format'] === null ? null : MtgoFormat::display($config['format']),
        ];
    }
}
