<?php

namespace App\Http\Controllers\Debug\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = Game::query()->orderByDesc('id');

        if ($request->input('match_id')) {
            $query->where('match_id', $request->input('match_id'));
        }

        return Inertia::render('debug/Games', [
            'games' => $query->paginate(50),
            'matchOptions' => MtgoMatch::query()
                ->withOpponentName()
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function (MtgoMatch $m) {
                    $date = $m->started_at->toLocal()->format('d/m');
                    $label = "#{$m->id}";
                    if ($m->opponent_name) {
                        $label .= " — vs {$m->opponent_name}";
                    }
                    if ($date) {
                        $label .= " ({$date})";
                    }

                    return ['label' => $label, 'value' => (string) $m->id];
                }),
            'filters' => [
                'match_id' => $request->input('match_id', ''),
            ],
        ]);
    }
}
