<?php

namespace App\Http\Controllers\Decks;

use App\Data\Front\DeckData;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(Request $request): Response
    {
        $query = Deck::forActiveAccount()
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

        $paginated = $query->paginate(12)->withQueryString();

        // Build format options: raw value => display label
        $formats = Deck::forActiveAccount()
            ->distinct()
            ->pluck('format')
            ->mapWithKeys(fn ($f) => [$f => MtgoMatch::displayFormat($f)])
            ->sortBy(fn ($label) => $label);

        return Inertia::render('decks/Index', [
            'decks' => $paginated->through(fn ($deck) => DeckData::from($deck)),
            'formats' => $formats,
            'filters' => [
                'format' => $request->input('format', ''),
                'search' => $request->input('search', ''),
                'sort' => $sort,
            ],
        ]);
    }
}
