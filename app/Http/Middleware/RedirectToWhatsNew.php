<?php

namespace App\Http\Middleware;

use App\Actions\WhatsNew\ShouldShowWhatsNew;
use App\Facades\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the main window to /whats-new once after a major or minor update.
 * Only full GET page loads qualify: partial reloads (fired on every updater
 * event), form posts, JSON calls and overlay windows pass straight through.
 */
class RedirectToWhatsNew
{
    /**
     * @var array<int, string>
     */
    private const SKIPPED_ROUTES = ['whats-new', 'updates.*', 'overlay.*', 'leagues.overlay'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request) || ! ShouldShowWhatsNew::run()) {
            return $next($request);
        }

        AppSettings::setWhatsNewSeenVersion((string) config('nativephp.version'));

        return redirect()->route('whats-new');
    }

    /**
     * Never in local dev: NativePHP copies the repo's storage/ over the dev
     * app data on every dev launch (electron-plugin php.ts), so the seen
     * version is lost and every start would land here.
     */
    private function eligible(Request $request): bool
    {
        return ! app()->isLocal()
            && $request->isMethod('GET')
            && ! $request->hasHeader('X-Inertia-Partial-Data')
            && ! $request->expectsJson()
            && ! $request->is('_native/*')
            && $request->route() !== null
            && ! $request->routeIs(...self::SKIPPED_ROUTES);
    }
}
