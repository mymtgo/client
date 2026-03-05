<?php

namespace App\Http\Middleware;

use App\Facades\Mtgo;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'status' => fn () => [
                'watcherRunning' => Mtgo::canRun(),
                'lastIngestAt' => LogEvent::max('ingested_at'),
                'pendingMatchCount' => MtgoMatch::submittable()->count(),
            ],
        ];
    }
}
