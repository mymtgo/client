<?php

namespace App\Actions\Decks;

use App\Actions\Cards\GetCardGameStats;
use App\Models\Deck;
use App\Models\DeckVersion;

class GetStandoutCards
{
    private const TOP_PERFORMER_MIN_GAMES = 20;

    private const SIDEBOARD_MIN_GAMES = 3;

    /**
     * Get standout card highlights for the deck dashboard.
     *
     * @return array<string, array|null>
     */
    public static function run(Deck $deck, ?DeckVersion $deckVersion = null): array
    {
        $stats = GetCardGameStats::run($deck, $deckVersion);

        $nonLandMaindeck = $stats->filter(fn ($c) => ! $c['isSideboard'] && ! str_contains($c['type'] ?? '', 'Land'));

        $topPerformer = $nonLandMaindeck
            ->filter(fn ($c) => $c['castGames'] >= self::TOP_PERFORMER_MIN_GAMES)
            ->sortByDesc(fn ($c) => $c['castGames'] > 0 ? $c['castWon'] / $c['castGames'] : 0)
            ->first();

        $mostCast = $nonLandMaindeck
            ->filter(fn ($c) => $c['castGames'] > 0)
            ->sortByDesc('castGames')
            ->first();

        $mostSeen = $stats
            ->filter(fn ($c) => ! $c['isSideboard'] && ! str_contains($c['type'] ?? '', 'Land') && $c['seenGames'] > 0)
            ->sortByDesc('seenGames')
            ->first();

        $mostPlayedLand = $stats
            ->filter(fn ($c) => ! $c['isSideboard'] && str_contains($c['type'] ?? '', 'Land') && $c['playedGames'] > 0)
            ->sortByDesc('playedGames')
            ->first();

        $mostSidedIn = $stats
            ->filter(fn ($c) => $c['isSideboard'] && $c['sidedInGames'] > 0 && $c['postboardGames'] >= self::SIDEBOARD_MIN_GAMES)
            ->sortByDesc('sidedInGames')
            ->first();

        $mostSidedOut = $nonLandMaindeck
            ->filter(fn ($c) => $c['sidedOutGames'] > 0 && $c['postboardGames'] >= self::SIDEBOARD_MIN_GAMES)
            ->sortByDesc('sidedOutGames')
            ->first();

        return [
            'topPerformer' => $topPerformer ? self::formatPct($topPerformer, 'cast win rate', $topPerformer['castWon'], $topPerformer['castGames']) : null,
            'mostCast' => $mostCast ? self::formatCount($mostCast, $mostCast['castGames'], $mostCast['totalGames'], 'games') : null,
            'mostSeen' => $mostSeen ? self::formatCount($mostSeen, $mostSeen['seenGames'], $mostSeen['totalGames'], 'games') : null,
            'mostPlayedLand' => $mostPlayedLand ? self::formatCount($mostPlayedLand, $mostPlayedLand['playedGames'], $mostPlayedLand['totalGames'], 'games') : null,
            'mostSidedIn' => $mostSidedIn ? self::formatCount($mostSidedIn, $mostSidedIn['sidedInGames'], $mostSidedIn['postboardGames'], 'postboard games') : null,
            'mostSidedOut' => $mostSidedOut ? self::formatCount($mostSidedOut, $mostSidedOut['sidedOutGames'], $mostSidedOut['postboardGames'], 'postboard games') : null,
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

    private static function formatCount(array $card, int $count, int $total, string $unit): array
    {
        return [
            'name' => $card['name'],
            'image' => $card['image'],
            'stat' => "{$count} of {$total} {$unit}",
        ];
    }
}
