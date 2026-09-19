<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\DashboardScope;
use App\Dashboard\WidgetType;
use App\Data\Front\MatchData;
use App\Models\MtgoMatch;

class RecentMatchesWidget implements WidgetType
{
    public function key(): string
    {
        return 'recent_matches';
    }

    public function label(): string
    {
        return 'Recent matches';
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

    public function resolve(array $config, DashboardScope $scope): mixed
    {
        return MatchData::collect(
            MtgoMatch::complete()
                ->when($scope->accountId, fn ($q, $id) => $q->forAccount($id))
                ->whereBetween('started_at', [$scope->start, $scope->end])
                ->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league', 'deck.cover', 'deck.archetype'])
                ->withCount([
                    'games as games_won_count' => fn ($q) => $q->where('won', true),
                    'games as games_lost_count' => fn ($q) => $q->where('won', false),
                ])
                ->orderByDesc('started_at')
                ->limit(10)
                ->get()
        );
    }
}
