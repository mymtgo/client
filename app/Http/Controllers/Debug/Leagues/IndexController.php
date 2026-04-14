<?php

namespace App\Http\Controllers\Debug\Leagues;

use App\Enums\LeagueState;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\League;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('debug/Leagues', [
            'leagues' => League::query()
                ->withTrashed()
                ->orderByDesc('id')
                ->paginate(50),
            'stateOptions' => collect(LeagueState::cases())->map(fn ($s) => [
                'label' => $s->value,
                'value' => $s->value,
            ]),
            'deckVersionOptions' => DeckVersion::query()
                ->join('decks', 'decks.id', '=', 'deck_versions.deck_id')
                ->orderByDesc('deck_versions.id')
                ->limit(200)
                ->get(['deck_versions.id', 'decks.name'])
                ->map(fn ($dv) => [
                    'label' => "#{$dv->id} — {$dv->name}",
                    'value' => (string) $dv->id,
                ]),
        ]);
    }
}
