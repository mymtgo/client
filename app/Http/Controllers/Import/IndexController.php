<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\ImportScan;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $deckVersions = DeckVersion::with(['deck' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('modified_at')
            ->get()
            ->map(fn (DeckVersion $v) => [
                'id' => $v->id,
                'deck_name' => $v->deck?->name ?? 'Unknown',
                'deck_deleted' => $v->deck?->trashed() ?? false,
                'modified_at' => $v->modified_at->format('d/m/Y'),
                'format' => $v->deck?->format ?? '',
            ]);

        $importedCount = MtgoMatch::where('imported', true)->count();

        $existingScan = ImportScan::latest()->first();

        return Inertia::render('import/Index', [
            'deckVersions' => $deckVersions,
            'importedCount' => $importedCount,
            'existingScan' => $existingScan ? [
                'id' => $existingScan->id,
                'deck_version_id' => $existingScan->deck_version_id,
                'deck_name' => $existingScan->deckVersion?->deck?->name ?? 'Unknown',
                'status' => $existingScan->status,
                'stage' => $existingScan->stage,
                'progress' => $existingScan->progress,
                'total' => $existingScan->total,
                'match_count' => $existingScan->isComplete() ? $existingScan->matches()->count() : 0,
                'formats' => $existingScan->isComplete() ? $existingScan->matches()
                    ->select('format_display')
                    ->distinct()
                    ->orderBy('format_display')
                    ->pluck('format_display')
                    ->toArray() : [],
                'confidence_stats' => $existingScan->isComplete() ? [
                    'high' => $existingScan->matches()->where('confidence', '>=', 0.6)->count(),
                    'low' => $existingScan->matches()->where('confidence', '<', 0.6)->whereNotNull('confidence')->count(),
                    'none' => $existingScan->matches()->whereNull('confidence')->count(),
                ] : null,
            ] : null,
        ]);
    }
}
