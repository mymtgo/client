<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
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

        return Inertia::render('import/Index', [
            'deckVersions' => $deckVersions,
        ]);
    }
}
