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

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('match_token', 'like', "%{$search}%")
                    ->orWhere('game_id', 'like', "%{$search}%")
                    ->orWhere('match_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('context')) {
            $query->where('context', $request->input('context'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        return Inertia::render('debug/LogEvents', [
            'logEvents' => $query->paginate(50)->withQueryString(),
            'filters' => [
                'search' => $request->input('search', ''),
                'event_type' => $request->input('event_type', ''),
                'context' => $request->input('context', ''),
                'category' => $request->input('category', ''),
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
            'contextOptions' => LogEvent::query()
                ->selectRaw('context, count(*) as cnt')
                ->whereNotNull('context')
                ->where('context', '!=', '')
                ->groupBy('context')
                ->orderByDesc('cnt')
                ->limit(50)
                ->pluck('context')
                ->sort()
                ->values()
                ->map(fn (string $c) => [
                    'label' => $c,
                    'value' => $c,
                ]),
            'categoryOptions' => LogEvent::query()
                ->selectRaw('category, count(*) as cnt')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->groupBy('category')
                ->orderByDesc('cnt')
                ->limit(50)
                ->pluck('category')
                ->sort()
                ->values()
                ->map(fn (string $c) => [
                    'label' => $c,
                    'value' => $c,
                ]),
        ]);
    }
}
