<?php

namespace App\Dashboard\Widgets;

use App\Actions\Dashboard\GetLastSession;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;

class LastSessionWidget implements WidgetType
{
    public function key(): string
    {
        return 'last_session';
    }

    public function label(): string
    {
        return 'Last session';
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
        return [];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function resolve(array $config, DashboardScope $scope): ?array
    {
        return GetLastSession::run($scope->accountId);
    }
}
