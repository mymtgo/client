<?php

namespace App\Http\Controllers\Challenges;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeStanding;
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

        $query = Challenge::query()
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

        $challenges = $query->paginate(20)->withQueryString();

        $challengeIds = collect($challenges->items())->pluck('id')->all();
        $localStandings = ChallengeStanding::whereIn('challenge_id', $challengeIds)
            ->where('is_local', true)
            ->select('challenge_id', 'rank', 'round')
            ->orderByDesc('round')
            ->get()
            ->unique('challenge_id')
            ->keyBy('challenge_id');

        $allFormats = Challenge::distinct()->whereNotNull('format')->pluck('format')->sort()->values()->all();

        return Inertia::render('challenges/Index', [
            'challenges' => $challenges,
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
