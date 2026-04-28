<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\Archetype;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup', 'setup/*', '_native/*', 'up')) {
            return $next($request);
        }

        if (AppSettings::setupCompleted()) {
            // In the testing environment the archetype-existence check is opt-in
            // via the `enforce_archetype_check` AppSettings flag. This prevents
            // the check from breaking unrelated Feature tests that don't seed
            // archetypes. Tests that explicitly verify this redirect behaviour
            // should call AppSettings::set('enforce_archetype_check', true).
            $shouldCheckArchetypes = ! App::environment('testing')
                || AppSettings::get('enforce_archetype_check', false);

            if (! $shouldCheckArchetypes) {
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
