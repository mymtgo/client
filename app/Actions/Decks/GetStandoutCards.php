<?php

namespace App\Actions\Decks;

use App\Actions\Cards\GetCardGameStats;
use App\Models\Deck;
use App\Models\DeckVersion;

class GetStandoutCards
{
    private const MIN_GAMES = 3;

    /**
     * Get standout card highlights for the deck dashboard.
     *
     * @return array<string, array|null>
     */
    public static function run(Deck $deck, ?DeckVersion $deckVersion = null): array
    {
        $stats = GetCardGameStats::run($deck, $deckVersion);

        $nonLandMaindeck = $stats->filter(fn ($c) => ! $c['isSideboard'] && ! str_contains($c['type'] ?? '', 'Land'));
        $withMinGames = $nonLandMaindeck->filter(fn ($c) => $c['totalCast'] >= self::MIN_GAMES);

        $topPerformer = $withMinGames
            ->sortByDesc(fn ($c) => $c['totalCast'] > 0 ? $c['castWon'] / $c['totalCast'] : 0)
            ->first();

        $mostCast = $nonLandMaindeck
            ->filter(fn ($c) => $c['totalCast'] > 0)
            ->sortByDesc('totalCast')
            ->first();

        $mostSeen = $stats
            ->filter(fn ($c) => ! $c['isSideboard'] && ! str_contains($c['type'] ?? '', 'Land') && $c['totalSeen'] > 0)
            ->sortByDesc('totalSeen')
            ->first();

        $mostPlayedLand = $stats
            ->filter(fn ($c) => ! $c['isSideboard'] && str_contains($c['type'] ?? '', 'Land') && $c['totalSeen'] > 0)
            ->sortByDesc('totalSeen')
            ->first();

        $mostSidedIn = $stats
            ->filter(fn ($c) => $c['isSideboard'] && $c['sidedInGames'] > 0 && $c['postboardGames'] >= self::MIN_GAMES)
            ->sortByDesc('sidedInGames')
            ->first();

        $mostSidedOut = $nonLandMaindeck
            ->filter(fn ($c) => $c['sidedOutGames'] > 0 && $c['postboardGames'] >= self::MIN_GAMES)
            ->sortByDesc('sidedOutGames')
            ->first();

        return [
            'topPerformer' => $topPerformer ? self::formatPct($topPerformer, 'cast win rate', $topPerformer['castWon'], $topPerformer['totalCast']) : null,
            'mostCast' => $mostCast ? self::formatCount($mostCast, 'Cast', $mostCast['totalCast'], $mostCast['totalGames']) : null,
            'mostSeen' => $mostSeen ? self::formatCount($mostSeen, 'Seen', $mostSeen['totalSeen'], $mostSeen['totalGames']) : null,
            'mostPlayedLand' => $mostPlayedLand ? self::formatCount($mostPlayedLand, 'Seen', $mostPlayedLand['totalSeen'], $mostPlayedLand['totalGames']) : null,
            'mostSidedIn' => $mostSidedIn ? self::formatPct($mostSidedIn, 'postboard games', $mostSidedIn['sidedInGames'], $mostSidedIn['postboardGames']) : null,
            'mostSidedOut' => $mostSidedOut ? self::formatPct($mostSidedOut, 'postboard games', $mostSidedOut['sidedOutGames'], $mostSidedOut['postboardGames']) : null,
        ];
    }

    private static function formatPct(array $card, string $description, int $numerator, int $denominator): array
    {
        $pct = $denominator > 0 ? (int) round($numerator / $denominator * 100) : 0;

        return [
            'name' => $card['name'],
            'image' => $card['image'],
            'stat' => "{$pct}% {$description}",
        ];
    }

    private static function formatCount(array $card, string $prefix, int $count, int $total): array
    {
        return [
            'name' => $card['name'],
            'image' => $card['image'],
            'stat' => "{$prefix} {$count} times in {$total} games",
        ];
    }
}
