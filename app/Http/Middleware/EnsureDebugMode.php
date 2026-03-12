<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Settings;
use Symfony\Component\HttpFoundation\Response;

class EnsureDebugMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) Settings::get('debug_mode')) {
            return redirect('/');
        }

        return $next($request);
    }
}
