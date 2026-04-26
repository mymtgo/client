<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetFilteredArchetypes;
use App\Http\Controllers\Controller;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreateController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = GetFilteredArchetypes::run($request);

        return Inertia::render('archetypes/Create', [
            'archetypes' => $data['archetypes'],
            'formats' => $data['formats'],
            'filters' => $data['filters'],
            'matches' => $this->matches($request),
        ]);
    }

    /**
     * @return array<int, array{id: int, opponent_username: string, started_at: string|null}>
     */
    private function matches(Request $request): array
    {
        $format = $request->input('format');

        if (! $format) {
            return [];
        }

        $search = $request->input('match_search');
        $limit = $search ? 50 : 10;
        $matchFormat = 'C'.strtoupper($format);

        $query = MtgoMatch::query()
            ->complete()
            ->where('format', $matchFormat)
            ->withOpponentName()
            ->orderByDesc('started_at')
            ->limit($limit);

        if ($search) {
            $query->whereHas('games.opponents', function ($q) use ($search) {
                $q->where('username', 'like', '%'.$search.'%');
            });
        }

        return $query->get()->map(fn (MtgoMatch $match) => [
            'id' => $match->id,
            'opponent_username' => $match->opponent_name ?? 'Unknown',
            'started_at' => optional($match->started_at)->toIso8601String(),
        ])->all();
    }
}
