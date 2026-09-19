<?php

namespace App\Dashboard\Widgets;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;
use App\Enums\LeagueState;
use App\Models\Card;
use App\Models\League;

class LimitedLeagueWidget implements WidgetType
{
    public function key(): string
    {
        return 'limited_league';
    }

    public function label(): string
    {
        return 'Last limited league';
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

    /**
     * Ignores the timeframe on purpose: the latest run is the latest ever.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(array $config, DashboardScope $scope): ?array
    {
        $league = League::query()
            ->limited()
            ->whereIn('state', [LeagueState::Complete, LeagueState::Dropped])
            ->with('deckVersion.deck.cover')
            ->orderByDesc('started_at')
            ->first();

        if ($league === null) {
            return null;
        }

        return [
            'run' => FormatLeagueRuns::run(collect([$league]), $scope->accountId)[0] ?? null,
            'setCode' => $league->set_code,
            'setName' => $league->set_code
                ? Card::query()->where('set_code', $league->set_code)->whereNotNull('set_name')->value('set_name')
                : null,
            'kind' => $league->kind->value,
        ];
    }
}
