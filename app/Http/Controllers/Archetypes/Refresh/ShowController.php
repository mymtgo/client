<?php

namespace App\Http\Controllers\Archetypes\Refresh;

use App\Actions\Archetypes\ComputeArchetypeRefreshPlan;
use App\Exceptions\OfflineModeException;
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
        } catch (OfflineModeException) {
            return redirect()
                ->route('archetypes.index')
                ->with('error', 'Offline mode is enabled. Turn it off in Settings to refresh archetypes.');
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
                'incoming' => false,
            ]);

        // Incoming API archetypes are valid successors too (a server-side rekey
        // removes and re-adds everything at once). They have no local id yet,
        // so their option id is the API uuid — resolved to a real id on apply.
        $incoming = collect($plan['added_rows'])->map(fn (array $row) => [
            'id' => $row['uuid'],
            'name' => $row['name'],
            'format' => $row['format'],
            'incoming' => true,
        ]);

        $options = $options->concat($incoming)->values();

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
