<?php

namespace App\Http\Controllers\Decks\Archetypes;

use App\Actions\Decks\GetArchetypeDeckVersionIds;
use App\Actions\Decks\GetDeckIndexSharedProps;
use App\Actions\Decks\RememberDeckIndexFilters;
use App\Concerns\HasTimeframeFilter;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shell for the stats tabs under an archetype on the deck listing. Resolves
 * the scoped deck versions and the shared sidebar props; subclasses supply
 * the tab body.
 */
abstract class ArchetypeTabController extends Controller
{
    use HasTimeframeFilter;

    abstract protected function component(): string;

    /**
     * @param  array<int, int>  $versionIds
     * @return array<string, mixed>
     */
    abstract protected function tabProps(Request $request, Archetype $archetype, array $versionIds, string $timeframe): array;

    public function __invoke(Request $request, Archetype $archetype): Response|RedirectResponse
    {
        $format = $request->filled('format') ? (string) $request->input('format') : null;

        // Fallbacks ("Other") span formats and have no matchup or card story
        // to tell; they only ever get the deck grid.
        if ($archetype->is_fallback) {
            return redirect()->route('decks.index', ['archetype' => $archetype->id, 'format' => $format]);
        }

        $shared = GetDeckIndexSharedProps::run($format, (string) $archetype->id);

        if ($shared['archetypeHeader'] === null) {
            return redirect()->route('decks.index', ['format' => $format]);
        }

        RememberDeckIndexFilters::put($request, $format, (string) $archetype->id);

        $timeframe = (string) $request->input('timeframe', 'alltime');
        $versionIds = GetArchetypeDeckVersionIds::run($archetype, $format, AppSettings::hideArchivedDecks());

        return Inertia::render($this->component(), [
            ...$shared,
            'archetype' => $archetype->id,
            'timeframe' => $timeframe,
            ...$this->tabProps($request, $archetype, $versionIds, $timeframe),
        ]);
    }
}
