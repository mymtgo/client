<?php

namespace App\Http\Controllers\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $format = $request->input('format');
        $state = $request->input('state', 'active');
        $participated = $request->boolean('participated', false);
        $search = $request->input('search');

        $query = Tournament::query()
            ->orderByRaw("CASE WHEN state = 'completed' THEN 1 ELSE 0 END")
            ->orderByDesc('started_at');

        if ($format) {
            $query->forFormat($format);
        }

        if ($state === 'active') {
            $query->active();
        } elseif ($state === 'completed') {
            $query->completed();
        }

        if ($participated) {
            $query->participated();
        }

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        $tournaments = $query->paginate(20)->withQueryString();

        $tournamentIds = collect($tournaments->items())->pluck('id')->all();
        $localStandings = TournamentStanding::whereIn('tournament_id', $tournamentIds)
            ->where('is_local', true)
            ->select('tournament_id', 'rank', 'round')
            ->orderByDesc('round')
            ->get()
            ->unique('tournament_id')
            ->keyBy('tournament_id');

        $allFormats = Tournament::distinct()->whereNotNull('format')->pluck('format')->sort()->values()->all();

        return Inertia::render('tournaments/Index', [
            'tournaments' => $tournaments,
            'localStandings' => $localStandings,
            'allFormats' => $allFormats,
            'filters' => [
                'format' => $format ?? '',
                'state' => $state,
                'participated' => $participated,
                'search' => $search ?? '',
            ],
        ]);
    }
}
