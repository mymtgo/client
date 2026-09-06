<?php

namespace App\Actions\Overlay;

use App\Actions\Cards\ApplyOpponentArchetypeFilter;
use App\Actions\Reports\GetReportSideboardOracles;
use App\Actions\SideboardGuides\GetVersionZoneQuantities;
use App\Actions\Util\Winrate;
use App\Data\Front\SideboardCardData;
use App\Data\Front\SideboardGuideData;
use App\Data\Front\SidedOutCardData;
use App\Enums\SideboardDirection;
use App\Enums\SideboardGuideScope;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\DeckVersion;
use App\Models\SideboardGuide;
use App\Models\SideboardGuideCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class BuildSideboardGuide
{
    /**
     * Sideboard lists for one deck against one opponent archetype.
     *
     * Two scopes on purpose: which cards are LISTED comes from $version (the
     * sideboard the player actually has available right now) while the numbers
     * beside them aggregate every version of the deck, or the samples would be
     * near-empty. $scope decides which subset of the version is listed; see
     * SideboardGuideScope. Plan and Editor require $plan.
     *
     * A per-card record is a record of games played with that configuration,
     * not a measure of the card's contribution: every card sided in for a game
     * shares that game's single result. `postboardRecord` is the baseline to
     * read them against.
     *
     * $community is how often the wider player base sides each card in and out
     * of this matchup, keyed by oracle_id (see FetchCommunitySideboardRates).
     * It only ever annotates.
     *
     * @param  Collection<string, array{sidedIn: int, sidedOut: int, games: int}>|null  $community
     */
    public static function run(
        DeckVersion $version,
        Archetype $archetype,
        ?Collection $community = null,
        SideboardGuideScope $scope = SideboardGuideScope::History,
        ?SideboardGuide $plan = null,
    ): SideboardGuideData {
        $community ??= collect();

        if ($scope !== SideboardGuideScope::History && $plan === null) {
            throw new InvalidArgumentException('Plan and Editor scopes require a sideboard guide.');
        }

        $planned = self::plannedQuantities($scope === SideboardGuideScope::History ? null : $plan);
        $hasPlan = $plan !== null && $scope !== SideboardGuideScope::History
            && ($planned['in'] !== [] || $planned['out'] !== []);

        $versionIds = $version->deck
            ? $version->deck->versions()->pluck('id')->all()
            : [$version->id];

        $stats = self::aggregate($versionIds, $archetype->id);
        $postboard = self::postboardTotals($versionIds, $archetype->id);

        $cards = collect($version->cards);
        $sideboardOracles = GetReportSideboardOracles::run([$version->id]);

        if ($scope === SideboardGuideScope::History) {
            $sideboardCards = self::byOracle(
                $cards->filter(fn (array $card) => $card['oracle_id'] !== null && $sideboardOracles->has($card['oracle_id'])),
                preferSideboard: true,
            );
            $maindeckCards = self::byOracle(
                $cards->filter(fn (array $card) => $card['oracle_id'] !== null && ! $sideboardOracles->has($card['oracle_id'])),
                preferSideboard: false,
            );
        } else {
            $zones = GetVersionZoneQuantities::run($version);
            // One representative entry per oracle for mtgo_id / metadata lookup only.
            $entries = self::byOracle($cards->filter(fn (array $card) => $card['oracle_id'] !== null), preferSideboard: true);

            $sideboardCards = self::zoneEntries($zones['in'], $entries, sideboard: true);
            $maindeckCards = self::zoneEntries($zones['out'], $entries, sideboard: false);
        }

        if ($scope === SideboardGuideScope::Plan) {
            $sideboardCards = $sideboardCards->filter(fn (array $card) => isset($planned['in'][$card['oracle_id']]));
            $maindeckCards = $maindeckCards->filter(fn (array $card) => isset($planned['out'][$card['oracle_id']]));
        }

        if ($scope !== SideboardGuideScope::History) {
            $sideboardCards = $sideboardCards->union(self::staleEntries($planned['in'], $sideboardCards, sideboard: true));
            $maindeckCards = $maindeckCards->union(self::staleEntries($planned['out'], $maindeckCards, sideboard: false));
        }

        $metadata = self::cardMetadata($cards->merge($sideboardCards->values())->merge($maindeckCards->values()));

        $sidedIn = $sideboardCards
            ->map(function (array $card) use ($stats, $metadata, $community, $planned, $scope) {
                $row = $stats->get($card['oracle_id']);
                // The version's own card row, falling back to the stats join's
                // copy of the same columns.
                $meta = $metadata->get($card['oracle_id']) ?? $row;
                $wins = (int) ($row->sided_in_won ?? 0);
                $losses = (int) ($row->sided_in_lost ?? 0);
                $games = (int) ($row->sided_in_games ?? 0);
                $peers = $community->get($card['oracle_id']);
                $plannedQuantity = $planned['in'][$card['oracle_id']] ?? null;

                return new SideboardCardData(
                    oracleId: $card['oracle_id'],
                    name: $meta->name ?? 'Unknown card',
                    type: $meta->type ?? null,
                    colorIdentity: $meta->color_identity ?? null,
                    image: self::imageUrl($meta),
                    artCrop: self::artCropUrl($meta),
                    quantity: $scope === SideboardGuideScope::Plan ? (int) $plannedQuantity : (int) $card['quantity'],
                    sidedInGames: $games,
                    wins: $wins,
                    losses: $losses,
                    winrate: $games > 0 ? Winrate::percentage($wins, $losses) : null,
                    communitySidedIn: $peers === null ? null : $peers['sidedIn'],
                    communityGames: $peers === null ? null : $peers['games'],
                    communityRate: self::rate($peers, 'sidedIn'),
                    plannedQuantity: $plannedQuantity,
                    stale: (bool) ($card['stale'] ?? false),
                );
            });

        // In History scope the community rate leads: "what does the field bring
        // in against this deck" is the question the panel exists to answer, and
        // it has a sample long before the player's own history does. Cards the
        // API does not know fall back to the local ordering rather than sorting
        // as 0%. With a plan, or in the editor, the list is the player's own
        // and reads best alphabetically.
        $sidedIn = ($scope === SideboardGuideScope::History
            ? $sidedIn->sort(fn (SideboardCardData $a, SideboardCardData $b) => [$b->communityRate ?? -1, $b->sidedInGames, $a->name] <=> [$a->communityRate ?? -1, $a->sidedInGames, $b->name])
            : $sidedIn->sortBy(fn (SideboardCardData $card) => $card->name))
            ->values()
            ->all();

        $sidedOut = $maindeckCards
            ->map(function (array $card) use ($stats, $metadata, $community, $planned, $scope) {
                $row = $stats->get($card['oracle_id']);
                $meta = $metadata->get($card['oracle_id']) ?? $row;
                $peers = $community->get($card['oracle_id']);
                $plannedQuantity = $planned['out'][$card['oracle_id']] ?? null;

                return new SidedOutCardData(
                    oracleId: $card['oracle_id'],
                    name: $meta->name ?? 'Unknown card',
                    type: $meta->type ?? null,
                    image: self::imageUrl($meta),
                    artCrop: self::artCropUrl($meta),
                    quantity: $scope === SideboardGuideScope::Plan ? (int) $plannedQuantity : (int) $card['quantity'],
                    sidedOutGames: (int) ($row->sided_out_games ?? 0),
                    communitySidedOut: $peers === null ? null : $peers['sidedOut'],
                    communityGames: $peers === null ? null : $peers['games'],
                    communityRate: self::rate($peers, 'sidedOut'),
                    plannedQuantity: $plannedQuantity,
                    stale: (bool) ($card['stale'] ?? false),
                );
            });

        if ($scope === SideboardGuideScope::History) {
            // A card nobody has ever cut is not a cut suggestion, so an
            // all-zero row is dropped whichever side it came from.
            $sidedOut = $sidedOut
                ->filter(fn (SidedOutCardData $card) => $card->sidedOutGames > 0 || ($card->communitySidedOut ?? 0) > 0)
                ->sort(fn (SidedOutCardData $a, SidedOutCardData $b) => [$b->communityRate ?? -1, $b->sidedOutGames, $a->name] <=> [$a->communityRate ?? -1, $a->sidedOutGames, $b->name]);
        } else {
            $sidedOut = $sidedOut->sortBy(fn (SidedOutCardData $card) => $card->name);
        }

        return new SideboardGuideData(
            sidedIn: $sidedIn,
            sidedOut: $sidedOut->values()->all(),
            postboardGames: $postboard['games'],
            postboardRecord: $postboard['wins'].' - '.($postboard['games'] - $postboard['wins']),
            hasPlan: $hasPlan,
        );
    }

    /**
     * The guide's planned copies per oracle id, split by direction.
     *
     * @return array{in: array<string, int>, out: array<string, int>}
     */
    private static function plannedQuantities(?SideboardGuide $plan): array
    {
        $planned = ['in' => [], 'out' => []];

        if ($plan === null) {
            return $planned;
        }

        foreach ($plan->cards as $card) {
            /** @var SideboardGuideCard $card */
            $key = $card->direction === SideboardDirection::In ? 'in' : 'out';
            $planned[$key][$card->oracle_id] = $card->quantity;
        }

        return $planned;
    }

    /**
     * Placeholder card entries for planned oracle ids missing from $present, so
     * a plan written for last week's 75 still names every card it mentions.
     * Shaped like a DeckVersion::$cards row with `stale` set.
     *
     * Staleness is zone-based, not version-based: $present is either the
     * version's sideboard cards or its maindeck cards, one call per direction.
     * An `in` entry is stale when the version's sideboard no longer holds the
     * oracle id, because you cannot bring in what is not in the board. An
     * `out` entry is stale when the maindeck no longer holds it. A card that
     * moved zones (sideboard to maindeck, or the reverse) is therefore stale
     * on the side it left and a normal row on the side it now occupies.
     *
     * @param  array<string, int>  $planned
     * @param  Collection<int|string, array<string, mixed>>  $present  the matching zone's cards, keyed by oracle_id
     * @return Collection<string, array{oracle_id: string, mtgo_id: null, quantity: int, sideboard: string, stale: bool}>
     */
    private static function staleEntries(array $planned, Collection $present, bool $sideboard): Collection
    {
        return collect($planned)
            ->keys()
            ->reject(fn (string $oracleId) => $present->has($oracleId))
            ->mapWithKeys(fn (string $oracleId) => [$oracleId => [
                'oracle_id' => $oracleId,
                'mtgo_id' => null,
                'quantity' => 0,
                'sideboard' => $sideboard ? '1' : '0',
                'stale' => true,
            ]]);
    }

    /**
     * Version card rows for one zone, keyed by oracle_id, with `quantity` set to
     * the zone's total copies (summed across printings and split entries) so the
     * editor's stepper ceiling agrees with UpdateSideboardGuideCardsRequest.
     *
     * @param  array<string, int>  $zone  oracle_id => copies in this zone
     * @param  Collection<int|string, array<string, mixed>>  $entries  one representative version row per oracle_id
     * @return Collection<string, non-empty-array<string, mixed>>
     */
    private static function zoneEntries(array $zone, Collection $entries, bool $sideboard): Collection
    {
        return collect($zone)->mapWithKeys(function (int $quantity, string $oracleId) use ($entries, $sideboard) {
            $entry = $entries->get($oracleId) ?? ['oracle_id' => $oracleId, 'mtgo_id' => null];

            return [$oracleId => [
                ...$entry,
                'oracle_id' => $oracleId,
                'quantity' => $quantity,
                'sideboard' => $sideboard ? '1' : '0',
            ]];
        });
    }

    /**
     * Collapse the version's card entries to one per oracle_id.
     *
     * GenerateDeckSignature emits one segment per source entry, so a card split
     * between the maindeck and the sideboard (2 main, 2 board) produces two
     * segments sharing an mtgo_id — and therefore an oracle_id. Two segments
     * can also share an oracle_id through two different printings. Either way
     * the card must be listed once, and for the sided-in list the quantity that
     * matters is the sideboard copies actually available to bring in, not the
     * maindeck ones.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return Collection<int|string, array<string, mixed>>
     */
    private static function byOracle(Collection $cards, bool $preferSideboard): Collection
    {
        return $cards
            ->groupBy('oracle_id')
            ->map(function (Collection $group) use ($preferSideboard) {
                if (! $preferSideboard) {
                    return $group->first();
                }

                return $group->first(
                    fn (array $card) => GetReportSideboardOracles::isSideboard($card['sideboard'] ?? false)
                ) ?? $group->first();
            });
    }

    /**
     * Name, colours and image for the version's own cards, keyed by oracle_id.
     *
     * Read from the `cards` table rather than the stats join: a card with no
     * history against this archetype has no card_game_stats row at all, and the
     * guide still has to name it — that is the common case on a first encounter
     * with an archetype, and the whole point of listing an untested sideboard.
     *
     * Looked up by the version's own mtgo_ids where possible so the image is the
     * printing the deck actually contains; pre-mtgo_id signatures carry only an
     * oracle_id, so those fall back to whatever printing is on file.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return Collection<string, Card>
     */
    private static function cardMetadata(Collection $cards): Collection
    {
        $oracleIds = $cards->pluck('oracle_id')->filter()->unique()->values();

        if ($oracleIds->isEmpty()) {
            return collect();
        }

        $preferred = $cards
            ->pluck('mtgo_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $metadata = collect();

        $rows = Card::query()
            ->whereIn('oracle_id', $oracleIds)
            ->get(['mtgo_id', 'oracle_id', 'name', 'type', 'color_identity', 'image', 'local_image', 'art_crop', 'local_art_crop']);

        foreach ($rows as $row) {
            if (in_array((string) $row->mtgo_id, $preferred, true) || ! $metadata->has($row->oracle_id)) {
                $metadata->put($row->oracle_id, $row);
            }
        }

        return $metadata;
    }

    /**
     * @param  array<int, int>  $versionIds
     * @return Collection<int|string, \stdClass>
     */
    private static function aggregate(array $versionIds, int $archetypeId): Collection
    {
        if (empty($versionIds)) {
            return collect();
        }

        $query = DB::table('card_game_stats as cgs')
            ->join(
                DB::raw('(SELECT oracle_id, name, color_identity, image, local_image, art_crop, local_art_crop FROM cards WHERE oracle_id IS NOT NULL GROUP BY oracle_id) as c'),
                'c.oracle_id',
                '=',
                'cgs.oracle_id'
            )
            ->where('cgs.opponent', false)
            ->whereIn('cgs.deck_version_id', $versionIds);

        ApplyOpponentArchetypeFilter::to($query, $archetypeId);

        return $query
            ->groupBy('cgs.oracle_id')
            ->selectRaw('
                cgs.oracle_id,
                c.name,
                c.color_identity,
                c.image,
                c.local_image,
                c.art_crop,
                c.local_art_crop,
                SUM(CASE WHEN cgs.sided_in THEN 1 ELSE 0 END) as sided_in_games,
                SUM(CASE WHEN cgs.sided_in AND cgs.won THEN 1 ELSE 0 END) as sided_in_won,
                SUM(CASE WHEN cgs.sided_in AND NOT cgs.won THEN 1 ELSE 0 END) as sided_in_lost,
                SUM(CASE WHEN cgs.sided_out THEN 1 ELSE 0 END) as sided_out_games
            ')
            ->get()
            ->keyBy('oracle_id');
    }

    /**
     * @param  array<int, int>  $versionIds
     * @return array{games: int, wins: int}
     */
    private static function postboardTotals(array $versionIds, int $archetypeId): array
    {
        if (empty($versionIds)) {
            return ['games' => 0, 'wins' => 0];
        }

        $query = DB::table('card_game_stats as cgs')
            ->where('cgs.opponent', false)
            ->where('cgs.is_postboard', true)
            ->whereIn('cgs.deck_version_id', $versionIds);

        ApplyOpponentArchetypeFilter::to($query, $archetypeId);

        $row = $query->selectRaw('
            COUNT(DISTINCT cgs.game_id) as games,
            COUNT(DISTINCT CASE WHEN cgs.won THEN cgs.game_id END) as wins
        ')->first();

        return [
            'games' => (int) ($row->games ?? 0),
            'wins' => (int) ($row->wins ?? 0),
        ];
    }

    /**
     * One community counter as a whole percentage of the games behind it, or
     * null when the API has no row for the card at all — which is a different
     * statement from "the field never does this", and must not read as 0%.
     *
     * @param  array{sidedIn: int, sidedOut: int, games: int}|null  $peers
     */
    private static function rate(?array $peers, string $counter): ?int
    {
        if ($peers === null || $peers['games'] <= 0) {
            return null;
        }

        return (int) round($peers[$counter] / $peers['games'] * 100);
    }

    private static function imageUrl(?object $row): ?string
    {
        if (! $row) {
            return null;
        }

        return $row->local_image
            ? Storage::disk('cards')->url($row->local_image)
            : $row->image;
    }

    private static function artCropUrl(?object $row): ?string
    {
        if (! $row) {
            return null;
        }

        return ($row->local_art_crop ?? null)
            ? Storage::disk('cards')->url($row->local_art_crop)
            : ($row->art_crop ?? null);
    }
}
