<?php

namespace App\Dashboard\Widgets;

use App\Actions\Dashboard\GetRollingForm;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;

class RollingFormWidget implements WidgetType
{
    public function key(): string
    {
        return 'rolling_form';
    }

    public function label(): string
    {
        return 'Rolling form';
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

    /** @return array<string, mixed> */
    public function resolve(array $config, DashboardScope $scope): array
    {
        return GetRollingForm::run($scope->accountId);
    }
}
