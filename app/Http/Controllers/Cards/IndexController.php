<?php

namespace App\Http\Controllers\Cards;

use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Card;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    /**
     * SQL expression that groups printings by oracle_id, keeping cards with a
     * null oracle_id as their own group (so unresolved stubs don't all collapse
     * into a single row).
     */
    private const GROUP_KEY = "coalesce(cards.oracle_id, 'id:' || cards.id)";

    /**
     * Card-type categories the type filter understands, in canonical precedence
     * order. A card's category is the first one its `type` string matches, so a
     * "Artifact Creature" counts as a Creature.
     */
    private const TYPE_CATEGORIES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land'];

    /** Columns the page consumes — keeps the Inertia payload lean. */
    private const COLUMNS = [
        'cards.id',
        'cards.name',
        'cards.set_code',
        'cards.type',
        'cards.sub_type',
        'cards.oracle_id',
        'cards.image',
        'cards.local_image',
    ];

    public function __invoke(Request $request): Response
    {
        $search = (string) $request->input('search', '');
        $format = (string) $request->input('format', '');
        $hiddenTypes = array_values(array_intersect(
            explode(',', (string) $request->input('hidden_types', '')),
            self::TYPE_CATEGORIES,
        ));
        $groupPrintings = $this->boolean($request, 'group_printings', true);

        $cards = $groupPrintings
            ? $this->groupedQuery($search, $hiddenTypes, $format)
            : $this->ungroupedQuery($search, $hiddenTypes, $format);

        $paginator = $cards
            ->orderByDesc('popularity')
            ->orderBy('cards.name')
            ->paginate(80)
            ->withQueryString()
            ->through(fn (Card $card) => $card->append('image_url'));

        return Inertia::render('cards/Index', [
            'cards' => $paginator,
            'formats' => Archetype::query()
                ->whereNotNull('format')
                ->distinct()
                ->pluck('format')
                ->mapWithKeys(fn (string $f) => [$f => MtgoMatch::displayFormat($f)])
                ->sortBy(fn (string $label) => $label),
            'filters' => [
                'search' => $search,
                'format' => $format,
                'hidden_types' => $hiddenTypes,
                'group_printings' => $groupPrintings,
            ],
            'typeCategories' => self::TYPE_CATEGORIES,
            'missingCount' => Card::query()
                ->where(fn (EloquentBuilder $q) => $q
                    ->whereNull('name')
                    ->orWhereNull('scryfall_id')
                    ->orWhereNull('image'))
                ->count(),
            'totalCount' => Card::query()->count(),
        ]);
    }

    /**
     * One row per printing; popularity is the distinct archetypes that printing
     * appears in. When a format is given, only printings seen in that format's
     * decks are returned and popularity is scoped to it.
     *
     * @param  list<string>  $hiddenTypes
     */
    private function ungroupedQuery(string $search, array $hiddenTypes, string $format): EloquentBuilder
    {
        $popularity = $this->popularityBase($format)
            ->selectRaw('adc.card_id as card_id, count(distinct ad.archetype_id) as popularity')
            ->groupBy('adc.card_id');

        $query = $this->applyFilters(Card::query(), $search, $hiddenTypes)
            ->select(self::COLUMNS)
            ->selectRaw('coalesce(pop.popularity, 0) as popularity');

        return $format === ''
            ? $query->leftJoinSub($popularity, 'pop', 'pop.card_id', '=', 'cards.id')
            : $query->joinSub($popularity, 'pop', 'pop.card_id', '=', 'cards.id');
    }

    /**
     * Printings collapsed by oracle_id. The representative row is the most
     * recently created printing; popularity counts distinct archetypes across
     * every printing sharing the oracle_id. A format scopes both the rows and
     * the popularity count to that format's decks.
     *
     * @param  list<string>  $hiddenTypes
     */
    private function groupedQuery(string $search, array $hiddenTypes, string $format): EloquentBuilder
    {
        $ranked = $this->applyFilters(Card::query(), $search, $hiddenTypes)
            ->selectRaw('cards.*')
            ->selectRaw(self::GROUP_KEY.' as gk')
            ->selectRaw('row_number() over (partition by '.self::GROUP_KEY.' order by cards.created_at desc, cards.id desc) as rn');

        $popularity = $this->popularityBase($format)
            ->selectRaw(self::GROUP_KEY.' as gk')
            ->selectRaw('count(distinct ad.archetype_id) as popularity')
            ->join('cards', 'cards.id', '=', 'adc.card_id')
            ->groupByRaw(self::GROUP_KEY);

        $query = Card::query()
            ->fromSub($ranked, 'cards')
            ->where('rn', 1)
            ->select(self::COLUMNS)
            ->selectRaw('coalesce(pop.popularity, 0) as popularity');

        return $format === ''
            ? $query->leftJoinSub($popularity, 'pop', 'pop.gk', '=', 'cards.gk')
            : $query->joinSub($popularity, 'pop', 'pop.gk', '=', 'cards.gk');
    }

    /**
     * Base query over the archetype_deck_cards pivot (aliased `adc`) joined to
     * its decks (aliased `ad`), optionally constrained to decks whose archetype
     * belongs to the given format.
     */
    private function popularityBase(string $format): EloquentBuilder
    {
        return Card::query()
            ->from('archetype_deck_cards as adc')
            ->join('archetype_decks as ad', 'ad.id', '=', 'adc.archetype_deck_id')
            ->when($format !== '', fn (Builder $q) => $q
                ->join('archetypes as a', 'a.id', '=', 'ad.archetype_id')
                ->where('a.format', $format));
    }

    /**
     * @param  list<string>  $hiddenTypes
     */
    private function applyFilters(EloquentBuilder $query, string $search, array $hiddenTypes): EloquentBuilder
    {
        return $query
            ->when($hiddenTypes !== [], fn (Builder $q) => $q->whereRaw(
                $this->canonicalTypeExpression().' not in ('.implode(',', array_fill(0, count($hiddenTypes), '?')).')',
                $hiddenTypes,
            ))
            ->when($search !== '', fn (Builder $q) => $q->where('cards.name', 'like', "%{$search}%"));
    }

    /**
     * SQL CASE that resolves a card to its canonical type category (or 'Other'),
     * mirroring the precedence used by the front-end type filter.
     */
    private function canonicalTypeExpression(): string
    {
        $cases = '';
        foreach (self::TYPE_CATEGORIES as $category) {
            $cases .= "when cards.type like '%{$category}%' then '{$category}' ";
        }

        return "case {$cases}else 'Other' end";
    }

    private function boolean(Request $request, string $key, bool $default): bool
    {
        if (! $request->has($key)) {
            return $default;
        }

        return filter_var($request->input($key), FILTER_VALIDATE_BOOL);
    }
}
