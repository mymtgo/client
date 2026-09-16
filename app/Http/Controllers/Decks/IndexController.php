<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\BuildDeckSidebarOptions;
use App\Actions\Decks\GetDeckIndexSharedProps;
use App\Actions\Decks\RememberDeckIndexFilters;
use App\Actions\Limited\EnsureLimitedDeckVersion;
use App\Data\Front\DeckData;
use App\Facades\AppSettings;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $hideDeleted = AppSettings::hideArchivedDecks();
        ['format' => $format, 'archetype' => $archetype] = RememberDeckIndexFilters::resolve(
            $request,
            $this->archetypeFilter($request->input('archetype')),
        );

        // A stale `archetype` query param (e.g. switching format, or archiving
        // the last deck of that archetype) must not strand the user on an
        // empty grid with no visible filter. `none` is left alone: an empty
        // Unclassified scope is a legitimate empty result, not a stale one.
        if ($archetype !== '' && $archetype !== 'none') {
            $inScope = BuildDeckSidebarOptions::scopedDecks($format, $hideDeleted)
                ->where('archetype_id', (int) $archetype)
                ->exists();

            if (! $inScope) {
                $archetype = '';
                RememberDeckIndexFilters::forgetArchetype($request);
            }
        }

        $query = Deck::forActiveAccount()
            ->where('format', '!=', EnsureLimitedDeckVersion::FORMAT)
            ->with(['cover', 'archetype' => fn ($q) => $q->withExists('decks')])
            ->withCount(['wonMatches', 'lostMatches', 'matches'])
            ->withMax('matches', 'started_at');

        if ($format !== null) {
            $query->where('format', $format);
        }

        if ($archetype === 'none') {
            $query->whereNull('archetype_id');
        } elseif ($archetype !== '') {
            $query->where('archetype_id', (int) $archetype);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        if ($hideDeleted) {
            $query->whereNull('deleted_at');
        }

        $sort = $request->input('sort', 'lastPlayed');
        $query = match ($sort) {
            'winRate' => $query->orderByRaw('CASE WHEN matches_count > 0 THEN CAST(won_matches_count AS FLOAT) / matches_count ELSE 0 END DESC'),
            'matchCount' => $query->orderByDesc('matches_count'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('matches_max_started_at'),
        };

        $paginated = $query->paginate(AppSettings::decksPerPage())->withQueryString();

        // Filters and page size are toggled from the listing itself, and those
        // toggles redirect back to whatever page the user was on. Shrink the
        // result set from page 3 and that page no longer exists, which renders
        // as an empty grid rather than an obviously wrong page number, so walk
        // back to the last page that does.
        if ($paginated->isEmpty() && $paginated->currentPage() > 1) {
            return redirect()->to($paginated->url($paginated->lastPage()));
        }

        return Inertia::render('decks/Index', [
            'decks' => $paginated->through(fn ($deck) => DeckData::from($deck)),
            ...GetDeckIndexSharedProps::run($format, $archetype, (string) $request->input('search', ''), (string) $sort),
        ]);
    }

    /**
     * `none` selects unclassified decks, digits select one archetype, anything
     * else is treated as no filter rather than a query for archetype "abc".
     */
    protected function archetypeFilter(mixed $raw): string
    {
        if ($raw === 'none') {
            return 'none';
        }

        if (is_string($raw) && ctype_digit($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        return '';
    }
}
