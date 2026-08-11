<?php

namespace App\Actions\Overlay;

use App\Actions\Cards\ApplyOpponentArchetypeFilter;
use App\Actions\Reports\GetReportSideboardOracles;
use App\Actions\Util\Winrate;
use App\Data\Front\SideboardCardData;
use App\Data\Front\SideboardGuideData;
use App\Data\Front\SidedOutCardData;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BuildSideboardGuide
{
    /**
     * Sideboard history for one deck against one opponent archetype.
     *
     * Two scopes on purpose: which cards are LISTED comes from $version — the
     * sideboard the player actually has available right now — while the numbers
     * beside them aggregate every version of the deck, or the samples would be
     * near-empty.
     *
     * A per-card record is a record of games played with that configuration,
     * not a measure of the card's contribution: every card sided in for a game
     * shares that game's single result. `postboardRecord` is the baseline to
     * read them against.
     */
    public static function run(DeckVersion $version, Archetype $archetype): SideboardGuideData
    {
        $versionIds = $version->deck
            ? $version->deck->versions()->pluck('id')->all()
            : [$version->id];

        $stats = self::aggregate($versionIds, $archetype->id);
        $postboard = self::postboardTotals($versionIds, $archetype->id);

        $cards = collect($version->cards);
        $sideboardOracles = GetReportSideboardOracles::run([$version->id]);
        $metadata = self::cardMetadata($cards);

        $sidedIn = self::byOracle(
            $cards->filter(
                fn (array $card) => $card['oracle_id'] !== null && $sideboardOracles->has($card['oracle_id'])
            ),
            preferSideboard: true,
        )
            ->map(function (array $card) use ($stats, $metadata) {
                $row = $stats->get($card['oracle_id']);
                // The version's own card row, falling back to the stats join's
                // copy of the same columns.
                $meta = $metadata->get($card['oracle_id']) ?? $row;
                $wins = (int) ($row->sided_in_won ?? 0);
                $losses = (int) ($row->sided_in_lost ?? 0);
                $games = (int) ($row->sided_in_games ?? 0);

                return new SideboardCardData(
                    oracleId: $card['oracle_id'],
                    name: $meta->name ?? 'Unknown card',
                    colorIdentity: $meta->color_identity ?? null,
                    image: self::imageUrl($meta),
                    quantity: (int) $card['quantity'],
                    sidedInGames: $games,
                    wins: $wins,
                    losses: $losses,
                    winrate: $games > 0 ? Winrate::percentage($wins, $losses) : null,
                );
            })
            ->sort(fn (SideboardCardData $a, SideboardCardData $b) => [$b->sidedInGames, $a->name] <=> [$a->sidedInGames, $b->name])
            ->values()
            ->all();

        $sidedOut = self::byOracle(
            $cards->filter(
                fn (array $card) => $card['oracle_id'] !== null && ! $sideboardOracles->has($card['oracle_id'])
            ),
            preferSideboard: false,
        )
            ->map(function (array $card) use ($stats, $metadata) {
                $row = $stats->get($card['oracle_id']);
                $meta = $metadata->get($card['oracle_id']) ?? $row;

                return new SidedOutCardData(
                    oracleId: $card['oracle_id'],
                    name: $meta->name ?? 'Unknown card',
                    image: self::imageUrl($meta),
                    sidedOutGames: (int) ($row->sided_out_games ?? 0),
                );
            })
            ->filter(fn (SidedOutCardData $card) => $card->sidedOutGames > 0)
            ->sort(fn (SidedOutCardData $a, SidedOutCardData $b) => [$b->sidedOutGames, $a->name] <=> [$a->sidedOutGames, $b->name])
            ->values()
            ->all();

        return new SideboardGuideData(
            sidedIn: $sidedIn,
            sidedOut: $sidedOut,
            postboardGames: $postboard['games'],
            postboardRecord: $postboard['wins'].' - '.($postboard['games'] - $postboard['wins']),
        );
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
            ->get(['mtgo_id', 'oracle_id', 'name', 'color_identity', 'image', 'local_image']);

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
                DB::raw('(SELECT oracle_id, name, color_identity, image, local_image FROM cards WHERE oracle_id IS NOT NULL GROUP BY oracle_id) as c'),
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

    private static function imageUrl(?object $row): ?string
    {
        if (! $row) {
            return null;
        }

        return $row->local_image
            ? Storage::disk('cards')->url($row->local_image)
            : $row->image;
    }
}
