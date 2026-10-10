<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The farewell release (0.47.0). First in the web group, so it answers before
 * the session, the shared props, the what's-new redirect and route model
 * binding run: no page reads or writes anything. Every GET is the farewell
 * screen and every other method is refused with 410, so no controller runs
 * and no API call or database write can happen. The one exception is the
 * download button's own route, which only opens the browser.
 */
class ShowFarewell
{
    public const COMPONENT = 'Farewell';

    public const TITLE = 'MyMTGO has a new app';

    public const BODY = 'MyMTGO 1.0 replaces this app. Download it and it imports your matches, decks and settings from this app the first time it opens. This app no longer records or syncs your matches.';

    public const DOWNLOAD_LABEL = 'Download MyMTGO';

    public const SMARTSCREEN = "Windows may warn that it protected your PC, because MyMTGO's installer is not code signed. Choose More info, then Run anyway.";

    public const UNINSTALL = 'Once MyMTGO is installed you can uninstall this app. Your data stays on this computer.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('farewell.enabled', true)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return response('MyMTGO 1.0 replaces this app.', 410);
        }

        if ($request->routeIs('farewell.download')) {
            return $next($request);
        }

        return Inertia::render(self::COMPONENT, [
            'title' => self::TITLE,
            'body' => self::BODY,
            'downloadLabel' => self::DOWNLOAD_LABEL,
            'downloadUrl' => (string) config('farewell.download_url'),
            'smartScreen' => self::SMARTSCREEN,
            'uninstall' => self::UNINSTALL,
        ])->toResponse($request);
    }
}
