<?php

namespace App\Http\Controllers\Tournaments;

use App\Enums\TournamentTimelineEventType;
use App\Enums\TournamentType;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\TournamentStanding;
use App\Models\TournamentTimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $type = $request->input('type');
        $category = $request->input('category');

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

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
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
        $allCategories = Tournament::distinct()->whereNotNull('category')->pluck('category')->sort()->values()->all();
        $types = collect(TournamentType::cases())
            ->reject(fn (TournamentType $t) => $t === TournamentType::Limited)
            ->map(fn (TournamentType $t) => $t->value)
            ->values()
            ->all();

        $eliminatedIds = TournamentTimelineEvent::query()
            ->whereIn('tournament_id', $tournamentIds)
            ->where('event_type', TournamentTimelineEventType::PlayerEliminated->value)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tournament_standings')
                    ->whereColumn('tournament_standings.tournament_id', 'tournament_timeline_events.tournament_id')
                    ->whereColumn('tournament_standings.login_id', 'tournament_timeline_events.login_id')
                    ->where('tournament_standings.is_local', true);
            })
            ->distinct()
            ->pluck('tournament_id')
            ->all();

        return Inertia::render('tournaments/Index', [
            'tournaments' => $tournaments,
            'localStandings' => $localStandings,
            'allFormats' => $allFormats,
            'allCategories' => $allCategories,
            'types' => $types,
            'eliminatedIds' => $eliminatedIds,
            'filters' => [
                'format' => $format ?? '',
                'state' => $state,
                'participated' => $participated,
                'search' => $search ?? '',
                'type' => $type ?? '',
                'category' => $category ?? '',
            ],
        ]);
    }
}
