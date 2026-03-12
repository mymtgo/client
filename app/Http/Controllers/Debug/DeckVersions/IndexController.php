<?php

namespace App\Http\Controllers\Debug\DeckVersions;

use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('debug/DeckVersions', [
            'deckVersions' => DeckVersion::query()
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'deckOptions' => Deck::query()
                ->withTrashed()
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'name'])
                ->map(fn ($d) => [
                    'label' => "#{$d->id} — {$d->name}",
                    'value' => (string) $d->id,
                ]),
        ]);
    }
}
