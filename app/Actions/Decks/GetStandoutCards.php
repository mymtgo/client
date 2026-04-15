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
            'mostCast' => $mostCast ? self::formatGameCount($mostCast, 'Cast', $mostCast['castGames'], $mostCast['totalGames']) : null,
            'mostSeen' => $mostSeen ? self::formatGameCount($mostSeen, 'Seen', $mostSeen['seenGames'], $mostSeen['totalGames']) : null,
            'mostPlayedLand' => $mostPlayedLand ? self::formatGameCount($mostPlayedLand, 'Played', $mostPlayedLand['playedGames'], $mostPlayedLand['totalGames']) : null,
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

    private static function formatGameCount(array $card, string $verb, int $gameCount, int $totalGames): array
    {
        return [
            'name' => $card['name'],
            'image' => $card['image'],
            'stat' => "{$verb} in {$gameCount} of {$totalGames} games",
        ];
    }
}
