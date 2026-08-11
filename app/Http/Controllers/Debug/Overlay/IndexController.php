<?php

namespace App\Http\Controllers\Debug\Overlay;

use App\Actions\Debug\CreateFakeOverlayMatch;
use App\Actions\Overlay\DetectSideboarding;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $fake = MtgoMatch::query()
            ->where('token', 'like', CreateFakeOverlayMatch::TOKEN_PREFIX.'%')
            ->latest('id')
            ->first();

        return Inertia::render('debug/Overlay', [
            'fakeMatch' => $fake ? [
                'id' => $fake->id,
                'token' => $fake->token,
                'state' => $fake->state->value,
                'games' => $fake->games()->count(),
                'opponent' => $fake->games()->first()?->opponents()->first()?->username,
                'isSideboarding' => DetectSideboarding::run($fake),
            ] : null,
            'deckOptions' => Deck::query()
                ->whereHas('versions')
                ->orderBy('name')
                ->get(['id', 'name', 'format'])
                ->map(fn (Deck $deck) => [
                    'label' => $deck->name,
                    'value' => (string) $deck->id,
                    // Normalised to the archetypes table's convention
                    // ('CMODERN' → 'modern') so the page can pair them up.
                    'format' => strtolower(MtgoMatch::displayFormat((string) $deck->format)),
                ]),
            'archetypeOptions' => Archetype::query()
                ->where('is_fallback', false)
                ->whereHas('decks')
                ->orderBy('name')
                ->get(['id', 'name', 'format'])
                ->map(fn (Archetype $archetype) => [
                    'label' => "{$archetype->name} ({$archetype->format})",
                    'value' => (string) $archetype->id,
                    'format' => (string) $archetype->format,
                ]),
        ]);
    }
}
