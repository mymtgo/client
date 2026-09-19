<?php

namespace App\Dashboard\Widgets;

use App\Actions\Limited\Read\ResolveCatalogCards;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetType;
use App\Models\Card;
use App\Models\DraftPick;
use App\Models\League;

class LimitedPicksWidget implements WidgetType
{
    public const LIMIT = 10;

    public function key(): string
    {
        return 'limited_picks';
    }

    public function label(): string
    {
        return 'Top limited picks';
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
        return ['set_code' => null];
    }

    public function validateConfig(array $config): array
    {
        $set = $config['set_code'] ?? null;

        if ($set !== null && ! is_string($set)) {
            throw new InvalidWidgetConfig('Set code must be text.');
        }

        $set = $set === null ? null : strtoupper(trim($set));

        return ['set_code' => $set === '' ? null : $set];
    }

    /**
     * Ignores the timeframe on purpose: the set is the scope.
     *
     * @return array{setCode: string|null, setName: string|null, picks: array<int, array{catalogId: string, name: string|null, image: string|null, count: int}>}
     */
    public function resolve(array $config, DashboardScope $scope): array
    {
        $setCode = $this->resolveSet($config['set_code']);

        if ($setCode === null) {
            return ['setCode' => null, 'setName' => null, 'picks' => []];
        }

        $rows = DraftPick::query()
            ->join('drafts', 'drafts.id', '=', 'draft_picks.draft_id')
            ->join('leagues', 'leagues.id', '=', 'drafts.league_id')
            ->where('leagues.set_code', $setCode)
            ->whereNotNull('draft_picks.picked_catalog_id')
            ->groupBy('draft_picks.picked_catalog_id')
            ->orderByDesc('picks')
            ->orderBy('draft_picks.picked_catalog_id')
            ->limit(self::LIMIT)
            ->selectRaw('draft_picks.picked_catalog_id as catalog_id, COUNT(*) as picks')
            ->toBase()
            ->get();

        $cards = ResolveCatalogCards::run($rows->pluck('catalog_id'));

        return [
            'setCode' => $setCode,
            'setName' => Card::query()->where('set_code', $setCode)->whereNotNull('set_name')->value('set_name'),
            'picks' => $rows->map(function ($row) use ($cards) {
                $card = $cards[(string) $row->catalog_id] ?? null;

                return [
                    'catalogId' => (string) $row->catalog_id,
                    'name' => $card?->name,
                    'image' => $card?->image_url,
                    'count' => (int) $row->picks,
                ];
            })->values()->all(),
        ];
    }

    private function resolveSet(?string $configured): ?string
    {
        $limited = League::query()->limited()->whereNotNull('set_code');

        if ($configured !== null && (clone $limited)->where('set_code', $configured)->exists()) {
            return $configured;
        }

        return $limited->orderByDesc('started_at')->value('set_code');
    }
}
