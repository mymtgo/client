<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\Archetype;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup', 'setup/*', '_native/*', 'up')) {
            return $next($request);
        }

        if (AppSettings::setupCompleted()) {
            if (AppSettings::setupSkippedArchetypes()) {
                return $next($request);
            }

            try {
                $archetypesExist = Archetype::query()->exists();
            } catch (\Throwable) {
                $archetypesExist = false;
            }

            if ($archetypesExist) {
                return $next($request);
            }
        }

        return redirect('/setup');
    }
}
