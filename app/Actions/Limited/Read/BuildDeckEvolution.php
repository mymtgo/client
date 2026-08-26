<?php

namespace App\Actions\Limited\Read;

use App\Data\Front\LimitedCardData;
use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Card;
use App\Models\Game;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;
use Illuminate\Support\Collection;

/**
 * The story of one limited deck: what was drafted, which registered versions
 * MTGO sent at each match start, and how the board changed game to game.
 */
class BuildDeckEvolution
{
    /** @var array<string, string> */
    private const GROUPS = ['W' => 'White', 'U' => 'Blue', 'B' => 'Black', 'R' => 'Red', 'G' => 'Green', 'M' => 'Multicolour', 'C' => 'Colourless', 'L' => 'Lands'];

    /**
     * @return array{summary: array<string, mixed>, versions: array<int, array<string, mixed>>, pool: array{groups: array<int, array<string, mixed>>}, games: array<int, array<string, mixed>>, cards: array<string, LimitedCardData>}
     */
    public static function run(League $league): array
    {
        ['snapshots' => $snapshots, 'pool' => $pool, 'ids' => $poolIds] = self::poolInputs($league);
        $matches = $league->matches()->where('state', MatchState::Complete)->withOpponentName()->with('games.players')->orderBy('started_at')->get();

        $ids = $poolIds
            ->merge($matches->flatMap(fn (MtgoMatch $m) => $m->games->flatMap(fn (Game $g) => collect(self::localDeck($g))->pluck('mtgo_id'))))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $cards = ResolveCatalogCards::run($ids);
        $isBasic = fn (int $id): bool => $cards->get((string) $id)?->type === 'Basic Land';

        /**
         * Match numbering covers every match in the league, not just the
         * finished ones, so a snapshot registered for the match currently
         * being played still gets its real number.
         */
        $matchOrder = $league->matches()
            ->orderBy('started_at')
            ->pluck('id')
            ->values()
            ->mapWithKeys(fn (int $id, int $i) => [$id => $i + 1])
            ->all();

        $current = $snapshots->last();
        ['main' => $currentMain, 'side' => $currentSide, 'everSeen' => $everSeen] = self::currentZones($snapshots);
        $versions = self::versions($snapshots, $matchOrder, $cards, $pool, $everSeen, $isBasic);

        return [
            'summary' => [
                'drafted' => array_sum($pool),
                'mainSpells' => (int) collect($currentMain)->filter(fn (int $quantity, int|string $id) => ! $isBasic((int) $id))->sum(),
                'basics' => (int) collect($currentMain)->filter(fn (int $quantity, int|string $id) => $isBasic((int) $id))->sum(),
                'sideboard' => (int) array_sum($currentSide),
                'versionCount' => count($versions),
                'firstRegisteredAt' => $snapshots->first()?->captured_at?->toIso8601String(),
                'lastRegisteredAt' => $current?->captured_at?->toIso8601String(),
            ],
            'versions' => $versions,
            'pool' => ['groups' => self::poolGroups($pool, $currentMain, $currentSide, $everSeen, $cards, $isBasic)],
            'games' => self::games($matches, $snapshots),
            'cards' => $ids->mapWithKeys(fn (int $id) => [(string) $id => LimitedCardData::fromCatalog($id, $cards->get((string) $id))])->all(),
        ];
    }

    /**
     * Where each drafted card ended up in the current registered deck, keyed
     * by catalog id. Same rules as the `pool` block of run(), but without the
     * matches, games and versions that block does not depend on.
     *
     * @return array<int, string>
     */
    public static function poolStatuses(League $league): array
    {
        ['snapshots' => $snapshots, 'pool' => $pool, 'ids' => $ids] = self::poolInputs($league);

        $cards = ResolveCatalogCards::run($ids);
        $isBasic = fn (int $id): bool => $cards->get((string) $id)?->type === 'Basic Land';
        ['main' => $main, 'side' => $side, 'everSeen' => $everSeen] = self::currentZones($snapshots);

        $statuses = [];
        foreach (self::poolGroups($pool, $main, $side, $everSeen, $cards, $isBasic) as $group) {
            foreach ($group['cards'] as $card) {
                $statuses[(int) $card['catalogId']] = (string) $card['status'];
            }
        }

        return $statuses;
    }

    /**
     * The registered snapshots, the drafted pool and every catalog id the two
     * of them mention: everything the pool view needs and nothing else.
     *
     * @return array{snapshots: Collection<int, LimitedDeckSnapshot>, pool: array<int, int>, ids: Collection<int, int>}
     */
    private static function poolInputs(League $league): array
    {
        $league->loadMissing(['draft']);
        $snapshots = $league->deckSnapshots()->where('source', 'registered')->orderBy('captured_at')->get();
        $pool = $league->draft?->poolCounts() ?? [];

        $ids = collect(array_keys($pool))
            ->merge($snapshots->flatMap(fn (LimitedDeckSnapshot $s) => collect($s->cards)->pluck('catalog_id')))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return ['snapshots' => $snapshots, 'pool' => $pool, 'ids' => $ids];
    }

    /**
     * The main and sideboard of the latest registered version, plus every
     * catalog id that appeared in any registered version.
     *
     * @param  Collection<int, LimitedDeckSnapshot>  $snapshots
     * @return array{main: array<int, int>, side: array<int, int>, everSeen: array<int, int>}
     */
    private static function currentZones(Collection $snapshots): array
    {
        $current = $snapshots->last();

        return [
            'main' => $current ? self::zone($current->cards, false) : [],
            'side' => $current ? self::zone($current->cards, true) : [],
            'everSeen' => $snapshots
                ->flatMap(fn (LimitedDeckSnapshot $s) => collect($s->cards)->pluck('catalog_id')->map(fn ($id) => (int) $id))
                ->unique()
                ->all(),
        ];
    }

    /**
     * Collapse one zone of a card list into catalog id => quantity. Accepts
     * both the snapshot shape (`catalog_id`) and the game_player.deck_json
     * shape (`mtgo_id`). Zero quantities are dropped so a card listed at 0
     * reads as absent, and a row missing its id or its quantity is skipped
     * rather than folded into a phantom id 0.
     *
     * @param  array<int, array{catalog_id?: int|string, mtgo_id?: int|string, quantity: int|string, sideboard?: bool|int|string}>  $cards
     * @return array<int, int>
     */
    private static function zone(array $cards, bool $sideboard): array
    {
        $zone = [];
        foreach ($cards as $card) {
            if (filter_var($card['sideboard'] ?? false, FILTER_VALIDATE_BOOL) !== $sideboard) {
                continue;
            }
            if (! isset($card['catalog_id']) && ! isset($card['mtgo_id'])) {
                continue;
            }
            if (! isset($card['quantity'])) {
                continue;
            }
            $id = (int) ($card['catalog_id'] ?? $card['mtgo_id']);
            $zone[$id] = ($zone[$id] ?? 0) + (int) $card['quantity'];
        }
        ksort($zone);

        return array_filter($zone);
    }

    /**
     * The first registered version has nothing to be compared against: its
     * whole list would otherwise read as "added".
     *
     * @return array{added: array<int, array{catalogId: int, quantity: int}>, removed: array<int, array{catalogId: int, quantity: int}>}
     */
    private static function emptyDiff(): array
    {
        return ['added' => [], 'removed' => []];
    }

    /**
     * @param  array<int, int>  $before
     * @param  array<int, int>  $after
     * @return array{added: array<int, array{catalogId: int, quantity: int}>, removed: array<int, array{catalogId: int, quantity: int}>}
     */
    private static function diff(array $before, array $after): array
    {
        $added = [];
        $removed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $id) {
            $delta = ($after[$id] ?? 0) - ($before[$id] ?? 0);
            if ($delta > 0) {
                $added[] = ['catalogId' => (int) $id, 'quantity' => $delta];
            } elseif ($delta < 0) {
                $removed[] = ['catalogId' => (int) $id, 'quantity' => -$delta];
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Registered snapshots in capture order, with consecutive identical
     * snapshots folded into one version that lists every match it covered.
     * Each version carries its own pool grouping so the deck page can show
     * where every drafted card sat in that build, not only the latest one.
     *
     * @param  Collection<int, LimitedDeckSnapshot>  $snapshots
     * @param  array<int, int>  $matchOrder  match id => its number within the league
     * @param  Collection<string, Card>  $cards
     * @param  array<int, int>  $pool
     * @param  array<int, int>  $everSeen
     * @param  callable(int): bool  $isBasic
     * @return array<int, array<string, mixed>>
     */
    private static function versions(Collection $snapshots, array $matchOrder, Collection $cards, array $pool, array $everSeen, callable $isBasic): array
    {
        $out = [];
        $prevMain = [];
        $prevSide = [];
        $index = 0;

        foreach ($snapshots as $snapshot) {
            $main = self::zone($snapshot->cards, false);
            $side = self::zone($snapshot->cards, true);

            if ($out !== [] && $main === $prevMain && $side === $prevSide) {
                $out[count($out) - 1]['matchIds'][] = $snapshot->match_id;

                continue;
            }

            $index++;
            $isFirst = $out === [];
            $colors = collect(array_keys($main))
                ->flatMap(fn (int $id) => str_split((string) ($cards->get((string) $id)?->colors ?? '')))
                ->unique()
                ->intersect(['W', 'U', 'B', 'R', 'G'])
                ->implode('');

            $out[] = [
                'index' => $index,
                'signature' => (string) $snapshot->signature,
                'capturedAt' => $snapshot->captured_at?->toIso8601String(),
                'matchIds' => [$snapshot->match_id],
                'matchLabels' => [],
                'main' => (int) array_sum($main),
                'side' => (int) array_sum($side),
                'colors' => $colors,
                'diffMain' => $isFirst ? self::emptyDiff() : self::diff($prevMain, $main),
                'diffSide' => $isFirst ? self::emptyDiff() : self::diff($prevSide, $side),
                'isCurrent' => false,
                'pool' => ['groups' => self::poolGroups($pool, $main, $side, $everSeen, $cards, $isBasic)],
                'mainCards' => self::cardList($main),
                'sideCards' => self::cardList($side),
            ];
            $prevMain = $main;
            $prevSide = $side;
        }

        if ($out !== []) {
            $out[count($out) - 1]['isCurrent'] = true;
        }

        foreach ($out as &$version) {
            $version['matchIds'] = array_values(array_filter($version['matchIds']));
            $version['matchLabels'] = array_map(fn (int $id) => 'Match '.($matchOrder[$id] ?? '?'), $version['matchIds']);
        }

        return $out;
    }

    /**
     * The drafted pool grouped by colour, each card tagged with where it ended
     * up in the current registered deck. Basics are never drafted, so they are
     * left out entirely rather than reported as cut. With nothing registered
     * yet there is no deck to be in or out of, so every card reads as 'pool'
     * rather than as a cut.
     *
     * @param  array<int, int>  $pool
     * @param  array<int, int>  $main
     * @param  array<int, int>  $side
     * @param  array<int, int>  $everSeen
     * @param  Collection<string, Card>  $cards
     * @param  callable(int): bool  $isBasic
     * @return array<int, array<string, mixed>>
     */
    private static function poolGroups(array $pool, array $main, array $side, array $everSeen, Collection $cards, callable $isBasic): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            $groups[$key] = ['key' => $key, 'label' => $label, 'count' => 0, 'cards' => []];
        }

        foreach ($pool as $poolId => $quantity) {
            $id = (int) $poolId;
            if ($isBasic($id)) {
                continue;
            }

            $card = $cards->get((string) $id);
            $colors = array_values(array_intersect(str_split((string) ($card?->colors ?? '')), ['W', 'U', 'B', 'R', 'G']));
            $key = str_contains((string) $card?->type, 'Land') ? 'L' : (count($colors) > 1 ? 'M' : ($colors[0] ?? 'C'));
            $mainQty = $main[$id] ?? 0;
            $sideQty = $side[$id] ?? 0;
            $status = match (true) {
                $everSeen === [] => 'pool',
                $mainQty > 0 => 'main',
                $sideQty > 0, in_array($id, $everSeen, true) => 'side',
                default => 'cut',
            };

            $groups[$key]['cards'][] = ['catalogId' => $id, 'quantity' => (int) $quantity, 'status' => $status, 'mainQty' => $mainQty, 'sideQty' => $sideQty];
            $groups[$key]['count'] += (int) $quantity;
        }

        foreach ($groups as &$group) {
            usort($group['cards'], fn (array $a, array $b) => [self::statusRank($a['status']), $a['catalogId']] <=> [self::statusRank($b['status']), $b['catalogId']]);
        }

        return array_values(array_filter($groups, fn (array $group) => $group['cards'] !== []));
    }

    /**
     * A zone as an ordered list the decklist can render directly.
     *
     * @param  array<int, int>  $zone
     * @return array<int, array{catalogId: int, quantity: int}>
     */
    private static function cardList(array $zone): array
    {
        $out = [];
        foreach ($zone as $id => $quantity) {
            $out[] = ['catalogId' => (int) $id, 'quantity' => (int) $quantity];
        }

        return $out;
    }

    private static function statusRank(string $status): int
    {
        return match ($status) {
            'main' => 0,
            'side' => 1,
            'pool' => 2,
            default => 3,
        };
    }

    /**
     * The local player's deck list for a game, or null when MTGO gave us no
     * board data for it.
     *
     * @return array<int, array{mtgo_id: int|string, quantity: int, sideboard: bool}>|null
     */
    private static function localDeck(Game $game): ?array
    {
        $local = $game->players->first(fn ($player) => (bool) $player->pivot->is_local);
        $deck = $local?->pivot->deck_json;
        if (is_string($deck)) {
            $deck = json_decode($deck, true);
        }

        return is_array($deck) && $deck !== [] ? $deck : null;
    }

    /**
     * Per match, how each game's board differed from the deck registered for
     * that match. Game 1 is the registered deck by definition.
     *
     * @param  Collection<int, MtgoMatch>  $matches
     * @param  Collection<int, LimitedDeckSnapshot>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    private static function games(Collection $matches, Collection $snapshots): array
    {
        $byMatch = $snapshots->keyBy('match_id');

        return $matches->values()->map(function (MtgoMatch $match, int $i) use ($byMatch) {
            $registered = $byMatch->get($match->id);
            $registeredMain = $registered ? self::zone($registered->cards, false) : null;

            $games = $match->games->sortBy('started_at')->values()->map(function (Game $game, int $n) use ($registeredMain) {
                $number = $n + 1;
                if ($registeredMain === null) {
                    return ['number' => $number, 'added' => [], 'removed' => [], 'note' => 'no board data'];
                }

                if ($number === 1) {
                    return ['number' => 1, 'added' => [], 'removed' => [], 'note' => 'registered deck'];
                }

                $deck = self::localDeck($game);
                if ($deck === null) {
                    return ['number' => $number, 'added' => [], 'removed' => [], 'note' => 'no board data'];
                }

                $diff = self::diff($registeredMain, self::zone($deck, false));
                $note = $diff['added'] === [] && $diff['removed'] === [] ? 'no changes' : null;

                return ['number' => $number, ...$diff, 'note' => $note];
            })->all();

            return [
                'matchId' => $match->id,
                'label' => 'Match '.($i + 1),
                'opponentName' => $match->opponent_name ?? null,
                'result' => match ($match->outcome) {
                    MatchOutcome::Win => 'W',
                    MatchOutcome::Loss => 'L',
                    default => null,
                },
                'games' => $games,
            ];
        })->all();
    }
}
