<?php

namespace App\Actions\Overlay;

use App\Actions\Archetypes\AggregateOpponentCards;
use App\Actions\Archetypes\EstimateArchetypeLocally;
use App\Actions\DetermineDeckArchetype;
use App\Actions\Leagues\FetchOpponentScouting;
use App\Data\Front\OverlayOpponentData;
use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\Archetype;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ResolveOverlayOpponent
{
    /**
     * Minimum local-estimate confidence before the live guess overrides the
     * league and last-encountered sources. Mirrors the threshold
     * DetermineDeckArchetype uses to skip its API call.
     */
    private const LIVE_CONFIDENCE_THRESHOLD = 0.8;

    /** How long a live estimate stays valid for an unchanged revealed card set. */
    private const LIVE_CACHE_MINUTES = 30;

    /** The same window for the API estimate, which is the more expensive call. */
    private const API_CACHE_MINUTES = 30;

    public static function run(MtgoMatch $match): ?OverlayOpponentData
    {
        $opponent = self::findOpponent($match);

        if (! $opponent) {
            return null;
        }

        [$wins, $losses] = self::headToHead($match, $opponent);

        [$archetype, $source, $manual] = self::resolveArchetype($match, $opponent);

        return new OverlayOpponentData(
            username: $opponent->username,
            previousMatches: $wins + $losses,
            wins: $wins,
            losses: $losses,
            archetypeId: $archetype?->id,
            archetypeName: $archetype?->name,
            archetypeColors: $archetype?->color_identity,
            source: $source,
            manual: $manual,
        );
    }

    /**
     * The live match's opponent: the non-local player in its earliest game.
     */
    public static function findOpponent(MtgoMatch $match): ?Player
    {
        return $match->games()
            ->orderBy('started_at')
            ->first()
            ?->opponents()
            ->first();
    }

    /**
     * Completed matches against this opponent in the live match's format,
     * excluding the live one. A Pauper record says nothing about a Modern
     * pairing.
     *
     * Computed independently of archetype resolution: the record is useful
     * whether or not the opponent happens to have a 5-0 list on file.
     *
     * @return array{0: int, 1: int} [wins, losses]
     */
    private static function headToHead(MtgoMatch $match, Player $opponent): array
    {
        $base = MtgoMatch::complete()
            ->whereHas('games.opponents', fn ($q) => $q->where('players.id', $opponent->id))
            ->where('matches.id', '!=', $match->id)
            ->where('format', $match->format);

        return [
            (clone $base)->where('outcome', MatchOutcome::Win)->count(),
            (clone $base)->where('outcome', MatchOutcome::Loss)->count(),
        ];
    }

    /**
     * @return array{0: ?Archetype, 1: string, 2: bool} [archetype, source, manual]
     */
    private static function resolveArchetype(MtgoMatch $match, Player $opponent): array
    {
        $manualRow = MatchArchetype::query()
            ->where('mtgo_match_id', $match->id)
            ->where('player_id', $opponent->id)
            ->where('manual', true)
            ->with('archetype')
            ->first();

        if ($manualRow?->archetype) {
            return [$manualRow->archetype, 'manual', true];
        }

        $cards = self::revealedCards($match, $opponent);

        // AggregateOpponentCards guarantees mtgo_id is an int, but
        // EstimateArchetypeLocally's array shape is int|string to also
        // accommodate SyncDecks::prefillArchetype, which passes oracle_id
        // strings under the same key. Widen here rather than narrowing either
        // action's own (accurate) declared shape.
        /** @var Collection<int, array{mtgo_id: int|string, quantity: int}>|null $cards */
        $fingerprint = $cards ? self::fingerprint($cards) : null;

        if ($cards && $fingerprint) {
            $live = self::localEstimate($match, $cards, $fingerprint);

            if ($live) {
                return [$live, 'live', false];
            }
        }

        // What we last faced them on in this format. Above every API source:
        // it is first-hand, and the live read above replaces it as soon as
        // this match reveals a different deck.
        $faced = self::lastFaced($match, $opponent);

        if ($faced) {
            return [$faced, 'local', false];
        }

        $scouting = self::scouting($match, $opponent);

        $league = self::scoutedArchetype($scouting, 'league');

        if ($league) {
            return [$league, 'league', false];
        }

        // A deck the opponent played through the tracker was classified from
        // its full list, so it is as sound as a 5-0, only unpublished.
        $tracked = self::scoutedArchetype($scouting, 'tracked');

        if ($tracked) {
            return [$tracked, 'tracked', false];
        }

        // Below the full-list sources on purpose: a decklist filed under this
        // opponent's name is near-certain, while the API guess is scored from a
        // partial reveal. It sits above `observed`, which only knows what
        // they brought some other day.
        if ($cards && $fingerprint) {
            $api = self::apiEstimate($match, $opponent, $cards, $fingerprint);

            if ($api) {
                return [$api, 'api', false];
            }
        }

        // Another tracker user's read of this opponent, from what they revealed
        // in that match.
        $observed = self::scoutedArchetype($scouting, 'observed');

        if ($observed) {
            return [$observed, 'observed', false];
        }

        return [null, 'none', false];
    }

    /**
     * The archetype from our most recent earlier match against this opponent
     * in the live match's format. The live match's own row is skipped: it is
     * this match's guess, not history.
     */
    private static function lastFaced(MtgoMatch $match, Player $opponent): ?Archetype
    {
        return $opponent->matchArchetypes()
            ->join('matches', 'matches.id', '=', 'match_archetypes.mtgo_match_id')
            ->where('matches.id', '!=', $match->id)
            ->where('matches.format', $match->format)
            ->orderByDesc('matches.started_at')
            ->orderByDesc('match_archetypes.id')
            ->select('match_archetypes.*')
            ->with('archetype')
            ->first()
            ?->archetype;
    }

    /**
     * Everything this opponent has revealed so far, or null if that is nothing.
     *
     * @return Collection<int, array{mtgo_id: int, quantity: int}>|null
     */
    private static function revealedCards(MtgoMatch $match, Player $opponent): ?Collection
    {
        $cards = AggregateOpponentCards::run($match)[$opponent->id] ?? null;

        if (! $cards instanceof Collection || $cards->isEmpty()) {
            return null;
        }

        return $cards;
    }

    /**
     * A stable hash of the revealed card set, so both estimators can cache
     * against "nothing new has been revealed since last time".
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int}>  $cards
     */
    private static function fingerprint(Collection $cards): string
    {
        return md5($cards->sortBy('mtgo_id')->map(
            fn (array $card) => $card['mtgo_id'].':'.$card['quantity']
        )->implode('|'));
    }

    /**
     * Score the revealed cards against locally downloaded decklists. Cached
     * against the card-set fingerprint: EstimateArchetypeLocally scores every
     * variant for the format, which is far too expensive to redo on every poll
     * while nothing new has been revealed.
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int}>  $cards
     */
    private static function localEstimate(MtgoMatch $match, Collection $cards, string $fingerprint): ?Archetype
    {
        $estimate = Cache::remember(
            "overlay_live_archetype_{$match->id}_{$fingerprint}",
            now()->addMinutes(self::LIVE_CACHE_MINUTES),
            fn () => EstimateArchetypeLocally::run($cards, $match->format) ?? false,
        );

        if (! $estimate || $estimate['confidence'] < self::LIVE_CONFIDENCE_THRESHOLD) {
            return null;
        }

        return Archetype::query()->find($estimate['archetype_id']);
    }

    /**
     * Ask the API to classify the revealed cards. Its estimator falls back to a
     * vector scorer when its rules engine finds nothing, so it can name a deck
     * from a handful of cards where the local lists cannot.
     *
     * No confidence floor: the API answers or it doesn't, and a partial answer
     * is exactly what this position in the chain is for. Cached against the
     * card-set fingerprint so the overlay's 5s poll makes at most one call per
     * distinct reveal.
     *
     * Gated on stats sharing: this sends the opponent's revealed cards off the
     * machine, which is the same bargain ShipCardStats makes.
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int}>  $cards
     */
    private static function apiEstimate(
        MtgoMatch $match,
        Player $opponent,
        Collection $cards,
        string $fingerprint,
    ): ?Archetype {
        if (AppSettings::isOffline()) {
            return null;
        }

        $estimate = Cache::remember(
            "overlay_api_archetype_{$match->id}_{$fingerprint}",
            now()->addMinutes(self::API_CACHE_MINUTES),
            fn () => DetermineDeckArchetype::estimateViaApi(
                $cards,
                $match->format,
                $match->id,
                $opponent->id,
            ) ?? false,
        );

        if (! $estimate) {
            return null;
        }

        return Archetype::query()->find($estimate['archetype_id']);
    }

    /**
     * The API's answer for this opponent in this format, cached for an hour.
     * The key carries the format, since the answer differs per format, and a
     * version suffix: `cache.default` is the file driver and entries survive
     * the restart that installs an upgrade, so an entry in an older shape must
     * never be read back. The shape is re-checked anyway; a cache file is no
     * more trustworthy than a log line.
     *
     * @return array<string, mixed>|null
     */
    private static function scouting(MtgoMatch $match, Player $opponent): ?array
    {
        $scouting = Cache::remember(
            $opponent->username.'_'.$match->format.'_scouting_v3',
            now()->addHour(),
            fn () => FetchOpponentScouting::run($opponent->username, $match->format) ?? false,
        );

        return is_array($scouting) ? $scouting : null;
    }

    /**
     * @param  array<string, mixed>|null  $scouting
     */
    private static function scoutedArchetype(?array $scouting, string $source): ?Archetype
    {
        $archetype = $scouting[$source] ?? null;

        if (! is_array($archetype) || ! isset($archetype['uuid'])) {
            return null;
        }

        return Archetype::query()->where('uuid', $archetype['uuid'])->first();
    }
}
