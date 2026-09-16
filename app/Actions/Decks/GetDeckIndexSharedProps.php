<?php

namespace App\Actions\Decks;

use App\Data\Front\ArchetypeData;
use App\Facades\AppSettings;
use App\Models\Archetype;
use Inertia\Inertia;
use Spatie\LaravelData\DataCollection;

/**
 * Props every deck-listing page shares: the sidebar, the archetype header and
 * the filter echo. The grid page and the archetype tabs render the same shell
 * around different bodies.
 */
class GetDeckIndexSharedProps
{
    /**
     * @return array<string, mixed>
     */
    public static function run(?string $format, string $archetype, string $search = '', string $sort = 'lastPlayed'): array
    {
        $hideDeleted = AppSettings::hideArchivedDecks();

        return [
            // Sidebar counts ignore search and the archetype filter on purpose:
            // selecting a row must not collapse the sidebar to that one row.
            'formatOptions' => BuildDeckSidebarOptions::formatOptions($hideDeleted),
            'archetypeOptions' => BuildDeckSidebarOptions::archetypeOptions($format, $hideDeleted),
            'unclassifiedCount' => BuildDeckSidebarOptions::unclassifiedCount($format, $hideDeleted),
            'archetypeHeader' => BuildDeckArchetypeHeader::run($archetype, $format, $hideDeleted),
            // Inertia v3 refetches deferred props after every navigation, and
            // this list changes rarely, so resolve it once per session. The
            // picker never offers the system fallbacks and never reads the
            // decklist flag, so neither is loaded here.
            'archetypes' => Inertia::defer(fn () => ArchetypeData::collect(
                Archetype::query()->where('is_fallback', false)->orderBy('name')->get(),
                DataCollection::class,
            ))->once(),
            'filters' => [
                'format' => $format ?? '',
                'archetype' => $archetype,
                'search' => $search,
                'sort' => $sort,
                'hide_deleted' => $hideDeleted,
                'per_page' => AppSettings::decksPerPage(),
                'card_size' => AppSettings::deckCardSize(),
            ],
        ];
    }
}
