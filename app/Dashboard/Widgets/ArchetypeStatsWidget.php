<?php

namespace App\Dashboard\Widgets;

use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Util\Winrate;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetType;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;
use App\Support\MtgoFormat;

class ArchetypeStatsWidget implements WidgetType
{
    public function key(): string
    {
        return 'archetype_stats';
    }

    public function label(): string
    {
        return 'Archetype stats';
    }

    public function span(): int
    {
        return 3;
    }

    public function maxColumns(): int
    {
        return 6;
    }

    public function allowsMultiple(): bool
    {
        return true;
    }

    public function defaultConfig(): array
    {
        return ['archetype_id' => null];
    }

    public function validateConfig(array $config): array
    {
        $archetypeId = $config['archetype_id'] ?? null;

        if (! is_numeric($archetypeId) || (int) $archetypeId <= 0) {
            throw new InvalidWidgetConfig('An archetype must be chosen.');
        }

        return ['archetype_id' => (int) $archetypeId];
    }

    /**
     * Every deck of mine tagged with the archetype, rolled up. The record and
     * game winrate follow the timeframe; the latest league is the latest ever.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(array $config, DashboardScope $scope): ?array
    {
        $archetype = Archetype::query()->find($config['archetype_id']);

        if ($archetype === null) {
            return null;
        }

        $deckIds = Deck::query()
            ->withTrashed()
            ->where('archetype_id', $archetype->id)
            ->when($scope->accountId, fn ($q, $id) => $q->where('account_id', $id))
            ->pluck('id');

        $matches = MtgoMatch::complete()
            ->whereHas('deckVersion', fn ($q) => $q->whereIn('deck_id', $deckIds));

        $matchRecord = MatchRecord::fromQuery(
            (clone $matches)->whereBetween('matches.started_at', [$scope->start, $scope->end])
        );

        $games = (clone $matches)
            ->whereBetween('matches.started_at', [$scope->start, $scope->end])
            ->toBase()
            ->join('games', 'games.match_id', '=', 'matches.id')
            ->whereNotNull('games.won')
            ->selectRaw('SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as won, SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as lost')
            ->first();
        $gamesWon = (int) ($games->won ?? 0);
        $gamesLost = (int) ($games->lost ?? 0);

        $league = League::query()
            ->whereHas('matches', fn ($q) => $q->whereIn('matches.id', (clone $matches)->select('matches.id')))
            ->whereIn('state', ['complete', 'dropped'])
            ->with('deckVersion.deck.cover')
            ->orderByDesc('started_at')
            ->first();

        return [
            'archetype' => [
                'id' => $archetype->id,
                'name' => $archetype->name,
                'format' => MtgoFormat::display($archetype->format),
                'colorIdentity' => $archetype->color_identity,
            ],
            'deckCount' => $deckIds->count(),
            'matchRecord' => $matchRecord->toData(),
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'latestLeague' => $league ? (FormatLeagueRuns::run(collect([$league]), $scope->accountId)[0] ?? null) : null,
        ];
    }
}
