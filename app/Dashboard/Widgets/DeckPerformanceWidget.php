<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;
use App\Data\Front\DeckData;
use App\Models\Deck;
use Illuminate\Support\Collection;

class DeckPerformanceWidget implements WidgetType
{
    public function key(): string
    {
        return 'deck_performance';
    }

    public function label(): string
    {
        return 'Deck performance';
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

    /** @return Collection<int, DeckData> */
    public function resolve(array $config, DashboardScope $scope): Collection
    {
        $inRange = fn ($query) => $query->whereBetween('started_at', [$scope->start, $scope->end]);

        return Deck::forActiveAccount()
            ->with(['cover', 'archetype'])
            ->withCount([
                'wonMatches' => $inRange,
                'lostMatches' => $inRange,
                'matches' => $inRange,
            ])
            ->whereHas('matches', $inRange)
            ->get()
            ->map(fn (Deck $deck) => DeckData::from($deck));
    }
}
