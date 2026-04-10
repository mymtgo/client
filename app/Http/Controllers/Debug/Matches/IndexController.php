<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $matches = MtgoMatch::query()
            ->withOpponentName()
            ->orderByDesc('id')
            ->paginate(50);

        return Inertia::render('debug/Matches', [
            'matches' => $matches,
            'leagueOptions' => League::orderByDesc('id')->get(['id', 'name'])->map(fn ($l) => [
                'label' => "#{$l->id} — {$l->name}",
                'value' => (string) $l->id,
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
            'stateOptions' => collect(MatchState::cases())->map(fn ($s) => [
                'label' => $s->value,
                'value' => $s->value,
            ]),
        ]);
    }
}
