<?php

namespace App\Http\Controllers\Archetypes\Refresh;

use App\Actions\Archetypes\ApplyArchetypeRefresh;
use App\Exceptions\OfflineModeException;
use App\Http\Requests\ApplyArchetypeRefreshRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class ApplyController
{
    public function __invoke(ApplyArchetypeRefreshRequest $request): RedirectResponse
    {
        try {
            $summary = ApplyArchetypeRefresh::run($request->mappings());
        } catch (OfflineModeException) {
            return back()->with('error', 'Offline mode is enabled. Turn it off in Settings to refresh archetypes.');
        } catch (\Throwable $e) {
            Log::error('Archetype refresh apply failed', ['exception' => $e]);
            report($e);

            return back()->with('error', 'Could not connect to the archetype server. Please check your internet connection and try again.');
        }

        $message = "Archetypes refreshed: {$summary['added']} added, {$summary['updated']} updated, {$summary['removed']} removed.";

        if ($summary['remapped'] > 0) {
            $message .= " {$summary['remapped']} reassigned to renamed archetypes.";
        }

        if ($summary['matches_queued'] > 0) {
            $message .= " Re-detecting archetypes for {$summary['matches_queued']} ".($summary['matches_queued'] === 1 ? 'match' : 'matches').'.';
        }

        return redirect()
            ->route('archetypes.index')
            ->with('success', $message);
    }
}
