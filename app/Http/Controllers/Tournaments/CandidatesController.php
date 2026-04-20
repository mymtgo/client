<?php

namespace App\Http\Controllers\Tournaments;

use App\Data\Front\TournamentCandidateData;
use App\Enums\TournamentType;
use App\Models\MtgoMatch;
use App\Models\Tournament;
use Illuminate\Http\Request;

class CandidatesController
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'match_id' => ['required', 'integer', 'exists:matches,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        $match = MtgoMatch::findOrFail($request->integer('match_id'));
        $all = $request->boolean('all');

        $query = Tournament::query()->participated();

        if (! $all) {
            $type = TournamentType::fromPlayFormatCd($match->format);

            if (! $type) {
                return response()->json([]);
            }

            $query->where('type', $type)
                ->whereBetween('started_at', [
                    $match->started_at->copy()->subHours(12),
                    $match->started_at->copy()->addHours(12),
                ]);
        }

        $tournaments = $query->orderByDesc('scheduled_at')
            ->orderByDesc('started_at')
            ->get();

        return response()->json(TournamentCandidateData::collect($tournaments));
    }
}
