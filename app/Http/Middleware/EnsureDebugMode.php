<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDebugMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AppSettings::isDebugMode()) {
            return redirect('/');
        }

        return $next($request);
    }
}
