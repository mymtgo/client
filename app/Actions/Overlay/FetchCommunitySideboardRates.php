<?php

namespace App\Actions\Overlay;

use App\Actions\Cards\FetchExternalCardStats;
use App\Exceptions\ExternalCardStatsUnavailable;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FetchCommunitySideboardRates
{
    /** How long a matchup's community rates stay valid. */
    private const CACHE_HOURS = 6;

    /** How long a failed lookup is remembered, so an outage is not re-dialled every poll. */
    private const FAILURE_CACHE_MINUTES = 5;

    /**
     * How often the wider player base sides each card in and out of this deck's
     * archetype against one opponent archetype.
     *
     * Cached: the overlay re-renders this panel on a 5s poll, and these numbers
     * move on the API's own aggregate schedule, not per game.
     *
     * @return Collection<string, array{sidedIn: int, sidedOut: int, games: int}>
     */
    public static function run(DeckVersion $version, Archetype $opponent): Collection
    {
        if (AppSettings::isOffline()) {
            return collect();
        }

        $deck = $version->deck;
        $archetype = $deck?->archetype;

        // Nothing to ask about: the API aggregates by archetype, so an
        // unclassified deck has no row to look up.
        if (! $deck || ! $archetype) {
            return collect();
        }

        $key = 'overlay_sb_community_'.implode('_', [$archetype->uuid, $opponent->uuid, $deck->format]);

        $rates = Cache::get($key);

        if ($rates === null) {
            $rates = self::fetch($archetype, $opponent, (string) $deck->format);

            Cache::put(
                $key,
                $rates,
                $rates === false
                    ? now()->addMinutes(self::FAILURE_CACHE_MINUTES)
                    : now()->addHours(self::CACHE_HOURS),
            );
        }

        return collect($rates === false ? [] : $rates);
    }

    /**
     * @return array<string, array{sidedIn: int, sidedOut: int, games: int}>|false
     */
    private static function fetch(Archetype $archetype, Archetype $opponent, string $format): array|false
    {
        try {
            $response = FetchExternalCardStats::run(
                archetype: $archetype,
                format: $format,
                opponentArchetypeId: $opponent->id,
                // Both play and draw: sideboarding decisions are not split by
                // who went first, and halving the sample would only add noise.
                onPlay: null,
                isPostboard: true,
                perspective: 'mine',
            );
        } catch (ExternalCardStatsUnavailable) {
            return false;
        }

        $rates = [];

        foreach ($response->stats as $row) {
            $rates[(string) $row['oracleId']] = [
                'sidedIn' => (int) $row['sidedInGames'],
                'sidedOut' => (int) $row['sidedOutGames'],
                'games' => (int) $row['totalGames'],
            ];
        }

        return $rates;
    }
}
