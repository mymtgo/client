<?php

namespace App\Http\Controllers\Decks;

use App\Data\Front\ArchetypeData;
use App\Data\Front\DeckData;
use App\Data\Front\DeckGroupData;
use App\Data\Front\DeckGroupStatsData;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\DataCollection;

class IndexController
{
    public function __invoke(Request $request): Response
    {
        $query = Deck::forActiveAccount()
            ->with(['cover', 'archetype'])
            ->withCount(['wonMatches', 'lostMatches', 'matches'])
            ->withMax('matches', 'started_at');

        if ($request->filled('format')) {
            $query->where('format', $request->input('format'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $sort = $request->input('sort', 'lastPlayed');
        $query = match ($sort) {
            'winRate' => $query->orderByRaw('CASE WHEN won_matches_count + lost_matches_count > 0 THEN CAST(won_matches_count AS FLOAT) / (won_matches_count + lost_matches_count) ELSE 0 END DESC'),
            'matchCount' => $query->orderByDesc('matches_count'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('matches_max_started_at'),
        };

        $formats = Deck::forActiveAccount()
            ->distinct()
            ->pluck('format')
            ->mapWithKeys(fn ($f) => [$f => MtgoMatch::displayFormat($f)])
            ->sortBy(fn ($label) => $label);

        $filters = [
            'format' => $request->input('format', ''),
            'search' => $request->input('search', ''),
            'sort' => $sort,
        ];

        $grouped = AppSettings::decksGroupedByArchetype();

        if ($grouped) {
            $decks = $query->get();

            return Inertia::render('decks/Index', [
                'mode' => 'grouped',
                'groups' => $this->buildGroups($decks, $sort),
                'formats' => $formats,
                'filters' => $filters,
            ]);
        }

        $paginated = $query->paginate(12)->withQueryString();

        return Inertia::render('decks/Index', [
            'mode' => 'flat',
            'decks' => $paginated->through(fn ($deck) => DeckData::from($deck)),
            'formats' => $formats,
            'filters' => $filters,
        ]);
    }

    /**
     * @param  EloquentCollection<int, Deck>  $decks
     * @return array<int, DeckGroupData>
     */
    protected function buildGroups(EloquentCollection $decks, string $sort): array
    {
        $grouped = $decks->groupBy(fn (Deck $deck) => $deck->archetype_id ?? '__unassigned__');

        $groups = $grouped->map(function ($groupDecks, $key) {
            $first = $groupDecks->first();
            $archetype = $key === '__unassigned__' ? null : $first->archetype;

            return new DeckGroupData(
                archetype: $archetype ? ArchetypeData::fromModel($archetype) : null,
                stats: DeckGroupStatsData::fromDecks($groupDecks),
                decks: DeckData::collect($groupDecks, DataCollection::class),
            );
        })->values();

        $unassigned = $groups->firstWhere(fn (DeckGroupData $g) => $g->archetype === null);
        $assigned = $groups->filter(fn (DeckGroupData $g) => $g->archetype !== null);

        $sortedAssigned = match ($sort) {
            'winRate' => $assigned->sortByDesc(fn (DeckGroupData $g) => $g->stats->winrate ?? -1)->values(),
            'matchCount' => $assigned->sortByDesc(fn (DeckGroupData $g) => $g->stats->totalMatches)->values(),
            'name' => $assigned->sortBy(fn (DeckGroupData $g) => strtolower($g->archetype->name))->values(),
            default => $assigned->sortByDesc(fn (DeckGroupData $g) => $g->stats->lastPlayedAt)->values(),
        };

        $result = $sortedAssigned->all();

        if ($unassigned !== null) {
            $result[] = $unassigned;
        }

        return $result;
    }
}
