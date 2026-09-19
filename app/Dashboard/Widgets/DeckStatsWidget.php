<?php

namespace App\Dashboard\Widgets;

use App\Actions\Decks\GetDeckStats;
use App\Actions\Leagues\GetLatestLeague;
use App\Dashboard\DashboardScope;
use App\Dashboard\InvalidWidgetConfig;
use App\Dashboard\WidgetType;
use App\Models\Deck;
use App\Support\MtgoFormat;

class DeckStatsWidget implements WidgetType
{
    public function key(): string
    {
        return 'deck_stats';
    }

    public function label(): string
    {
        return 'Deck stats';
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
        return ['deck_id' => null];
    }

    public function validateConfig(array $config): array
    {
        $deckId = $config['deck_id'] ?? null;

        if (! is_numeric($deckId) || (int) $deckId <= 0) {
            throw new InvalidWidgetConfig('A deck must be chosen.');
        }

        return ['deck_id' => (int) $deckId];
    }

    /**
     * The record and game winrate follow the timeframe. The latest league is
     * the latest ever, matching the deck dashboard.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(array $config, DashboardScope $scope): ?array
    {
        $deck = Deck::query()->with('cover')->find($config['deck_id']);

        if ($deck === null) {
            return null;
        }

        $stats = GetDeckStats::run($deck, $scope->start, $scope->end);

        return [
            'deck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'format' => MtgoFormat::display($deck->format),
                'colorIdentity' => $deck->color_identity,
                'coverArt' => $deck->cover?->art_crop_url,
            ],
            'matchRecord' => $stats['matchRecord']->toData(),
            'gameWinrate' => $stats['gameWinrate'],
            'gamesWon' => $stats['gamesWon'],
            'gamesLost' => $stats['gamesLost'],
            'latestLeague' => GetLatestLeague::run($deck, $deck->matches()->pluck('matches.id')),
        ];
    }
}
