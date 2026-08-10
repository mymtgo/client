<?php

namespace App\Actions\Overlay;

use App\Actions\Archetypes\AggregateOpponentCards;
use App\Actions\Archetypes\EstimateArchetypeLocally;
use App\Actions\Leagues\FetchOpponentLeagueArchetype;
use App\Data\Front\OverlayOpponentData;
use App\Enums\MatchOutcome;
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

    private static function findOpponent(MtgoMatch $match): ?Player
    {
        return $match->games()
            ->with(['opponents'])
            ->orderBy('started_at')
            ->first()
            ?->opponents()
            ->first();
    }

    /**
     * Completed matches against this opponent, excluding the live one.
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
            ->where('matches.id', '!=', $match->id);

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

        $live = self::liveEstimate($match, $opponent);

        if ($live) {
            return [$live, 'live', false];
        }

        $league = self::leagueArchetype($match, $opponent);

        if ($league) {
            return [$league, 'league', false];
        }

        $lastSeen = $opponent->matchArchetypes()
            ->with('archetype')
            ->latest('id')
            ->first()
            ?->archetype;

        if ($lastSeen) {
            return [$lastSeen, 'local', false];
        }

        return [null, 'none', false];
    }

    /**
     * Score the opponent's revealed cards against locally downloaded
     * decklists. Cached against a hash of the card set: EstimateArchetypeLocally
     * scores every variant for the format, which is far too expensive to redo
     * on every poll while nothing new has been revealed.
     */
    private static function liveEstimate(MtgoMatch $match, Player $opponent): ?Archetype
    {
        $cards = AggregateOpponentCards::run($match)[$opponent->id] ?? null;

        if (! $cards instanceof Collection || $cards->isEmpty()) {
            return null;
        }

        $fingerprint = md5($cards->sortBy('mtgo_id')->map(
            fn (array $card) => $card['mtgo_id'].':'.$card['quantity']
        )->implode('|'));

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

    private static function leagueArchetype(MtgoMatch $match, Player $opponent): ?Archetype
    {
        $league = Cache::remember(
            $opponent->username.'_archetype',
            now()->addHour(),
            fn () => FetchOpponentLeagueArchetype::run($opponent->username, $match->format) ?? false,
        );

        if (! $league) {
            return null;
        }

        return Archetype::query()->where('name', $league['name'])->first();
    }
}
