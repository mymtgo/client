<?php

namespace App\Http\Controllers\Archetypes\Refresh;

use App\Actions\Archetypes\ComputeArchetypeRefreshPlan;
use App\Models\Archetype;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ShowController
{
    public function __invoke(): Response|RedirectResponse
    {
        try {
            $plan = ComputeArchetypeRefreshPlan::run();
        } catch (\Throwable $e) {
            Log::error('Archetype refresh preview failed', ['exception' => $e]);
            report($e);

            return redirect()
                ->route('archetypes.index')
                ->with('error', 'Could not connect to the archetype server. Please check your internet connection and try again.');
        }

        $removed = collect($plan['removed']);
        [$withMatches, $withoutMatches] = $removed->partition(fn (array $row) => $row['match_count'] > 0);

        $options = Archetype::query()
            ->whereNotIn('id', $removed->pluck('id'))
            ->whereNull('merged_into_id')
            ->orderBy('format')
            ->orderBy('name')
            ->get(['id', 'name', 'format'])
            ->map(fn (Archetype $archetype) => [
                'id' => $archetype->id,
                'name' => $archetype->name,
                'format' => $archetype->format,
            ]);

        return Inertia::render('archetypes/Refresh', [
            'added' => $plan['added'],
            'updated' => $plan['updated'],
            'removals' => $withMatches->values(),
            'removed_without_matches' => $withoutMatches->count(),
            'matches_affected' => count($plan['match_ids']),
            'options' => $options,
        ]);
    }
}
