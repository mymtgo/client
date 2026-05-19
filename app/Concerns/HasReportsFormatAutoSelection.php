<?php

namespace App\Concerns;

use App\Actions\Reports\GetReportFormatOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait HasReportsFormatAutoSelection
{
    /**
     * If only one format is available for the selected archetype, redirect to
     * the current route with the format pre-selected. Returns null otherwise.
     */
    protected function autoSelectSoleFormat(Request $request, ?int $archetypeId, ?string $format, string $routeName): ?RedirectResponse
    {
        if ($archetypeId === null || $format !== null) {
            return null;
        }

        $formats = GetReportFormatOptions::run($archetypeId);
        if ($formats->count() !== 1) {
            return null;
        }

        return redirect()->route($routeName, array_merge($request->query(), [
            'archetype' => $archetypeId,
            'format' => $formats->first()['value'],
        ]));
    }
}
