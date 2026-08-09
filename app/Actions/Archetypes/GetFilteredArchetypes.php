<?php

namespace App\Actions\Archetypes;

use App\Data\Front\ArchetypeData;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GetFilteredArchetypes
{
    private const SESSION_KEY = 'archetypes.filters';

    public static function run(Request $request): array
    {
        $filters = static::resolveFilters($request);

        $query = Archetype::query()->where('is_fallback', false)->orderBy('name')->withExists('decks');

        if ($filters['format'] !== '') {
            $query->forFormat($filters['format']);
        }

        if ($filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $paginated = $query->paginate(25)->withQueryString();

        $formats = Archetype::query()
            ->whereNotNull('format')
            ->distinct()
            ->pluck('format')
            ->mapWithKeys(fn ($f) => [$f => Str::title($f)])
            ->sortBy(fn ($label) => $label);

        return [
            'archetypes' => $paginated->through(fn ($archetype) => ArchetypeData::fromModel($archetype)),
            'formats' => $formats,
            'filters' => $filters,
        ];
    }

    /**
     * Resolve the sidebar filters, remembering the last set the user chose.
     *
     * Every archetype action redirects to archetypes.show with no query
     * string, so the request that re-renders the sidebar afterwards carries no
     * filters at all — the list came back unfiltered while the inputs still
     * showed the old terms, and the only way out was to retype them. The
     * request stays authoritative whenever it mentions a filter (including
     * mentioning it as empty, which is how the user clears one); the remembered
     * set only fills the silence.
     *
     * @return array{format: string, search: string}
     */
    private static function resolveFilters(Request $request): array
    {
        $remembered = $request->session()->get(static::SESSION_KEY, ['format' => '', 'search' => '']);

        /**
         * Partial reloads reuse these parameter names for other things — the
         * match picker on the create screen sends the form's chosen format and
         * a blank search — so only a full visit may change what we remember.
         */
        if ($request->hasHeader('X-Inertia-Partial-Data')) {
            return $remembered;
        }

        if ($request->has('search') || $request->has('format')) {
            // ConvertEmptyStringsToNull turns `?search=` into null, so cast
            // rather than relying on the input default.
            $filters = [
                'format' => (string) $request->input('format'),
                'search' => (string) $request->input('search'),
            ];

            $request->session()->put(static::SESSION_KEY, $filters);

            return $filters;
        }

        return $remembered;
    }
}
