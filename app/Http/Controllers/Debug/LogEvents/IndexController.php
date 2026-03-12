<?php

namespace App\Http\Controllers\Debug\LogEvents;

use App\Http\Controllers\Controller;
use App\Models\LogEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = LogEvent::query()->orderByDesc('id');

        if ($request->filled('match_token')) {
            $query->where('match_token', $request->input('match_token'));
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        return Inertia::render('debug/LogEvents', [
            'logEvents' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'match_token' => $request->input('match_token', ''),
                'event_type' => $request->input('event_type', ''),
            ],
            'eventTypeOptions' => LogEvent::query()
                ->whereNotNull('event_type')
                ->distinct()
                ->pluck('event_type')
                ->sort()
                ->values()
                ->map(fn (string $t) => [
                    'label' => $t,
                    'value' => $t,
                ]),
        ]);
    }
}
