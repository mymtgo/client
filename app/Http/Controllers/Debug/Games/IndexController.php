<?php

namespace App\Http\Controllers\Debug\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('debug/Games', [
            'games' => Game::query()->orderByDesc('id')->paginate(50),
            'matchOptions' => MtgoMatch::query()
                ->withTrashed()
                ->orderByDesc('id')
                ->limit(200)
                ->get(['id', 'token'])
                ->map(fn ($m) => [
                    'label' => "#{$m->id} — {$m->token}",
                    'value' => (string) $m->id,
                ]),
        ]);
    }
}
