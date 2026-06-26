<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use App\Models\SchemaUpgrade;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchemaUpgraded
{
    public function handle(Request $request, Closure $next): Response
    {
        // Allow-list: always reachable even in legacy state.
        if ($request->is('upgrade', 'upgrade/*', 'up', '_native/*')) {
            return $next($request);
        }

        // Fast path: version is already at target — no DB query needed.
        if (AppSettings::dataSchemaVersion() >= SchemaUpgrade::TARGET_DATA_VERSION) {
            return $next($request);
        }

        // Legacy data present → force upgrade flow.
        if (MtgoMatch::query()->whereNull('account_id')->exists()) {
            return redirect()->route('upgrade.show');
        }

        // Fresh/already-clean install → auto-bump and continue.
        AppSettings::setDataSchemaVersion(SchemaUpgrade::TARGET_DATA_VERSION);

        return $next($request);
    }
}
