<?php

namespace App\Actions\Archetypes;

use App\Data\Front\ArchetypeData;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GetFilteredArchetypes
{
    public static function run(Request $request): array
    {
        $query = Archetype::query()->orderBy('name');

        if ($request->filled('format')) {
            $query->where('format', $request->input('format'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $paginated = $query->paginate(25)->withQueryString();

        $formats = Archetype::query()
            ->distinct()
            ->pluck('format')
            ->mapWithKeys(fn ($f) => [$f => Str::title($f)])
            ->sortBy(fn ($label) => $label);

        return [
            'archetypes' => $paginated->through(fn ($archetype) => ArchetypeData::fromModel($archetype)),
            'formats' => $formats,
            'filters' => [
                'format' => $request->input('format', ''),
                'search' => $request->input('search', ''),
            ],
        ];
    }
}
