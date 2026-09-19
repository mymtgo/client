<?php

namespace App\Dashboard;

interface WidgetType
{
    public function key(): string;

    public function label(): string;

    /** Preferred columns of a 12-column grid: 3, 4, 6 or 12. */
    public function span(): int;

    /** Widest the card may stretch to when filling a row. */
    public function maxColumns(): int;

    public function allowsMultiple(): bool;

    /** @return array<string, mixed> */
    public function defaultConfig(): array;

    /**
     * Shape-check and normalise stored config. Never touches the database.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws InvalidWidgetConfig
     */
    public function validateConfig(array $config): array;

    /** @param array<string, mixed> $config */
    public function resolve(array $config, DashboardScope $scope): mixed;
}
