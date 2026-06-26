<?php

use App\Facades\Mtgo;
use App\Http\Middleware\EnsureDebugMode;
use App\Http\Middleware\EnsureSchemaUpgraded;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance']);
        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            EnsureSchemaUpgraded::class,
        ]);
        $middleware->alias([
            'debug' => EnsureDebugMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Don't report NativePHP internal route errors — these are framework-level
        // race conditions (e.g. Electron event bus auth failures during startup).
        $exceptions->dontReportWhen(function (Throwable $e) {
            return $e instanceof HttpExceptionInterface && request()->is('_native/*');
        });

        // Don't report Blade compile rename races on Windows. The packaged Electron
        // app runs `artisan optimize` → `view:cache` at startup; occasionally
        // AV/indexer still holds a just-written temp file when rename() fires.
        // The app keeps working — lazy compilation handles any view that missed
        // the cache — so this warning is noise, not a user-facing bug.
        $exceptions->dontReportWhen(function (Throwable $e) {
            return $e instanceof ErrorException
                && str_contains($e->getMessage(), 'rename(')
                && str_contains($e->getMessage(), 'Access is denied')
                && str_contains($e->getFile(), 'Filesystem');
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (! in_array($status, [403, 404, 419, 500, 503])) {
                return $response;
            }

            // Don't leak raw exception messages (SQL snippets, file paths, etc.) to
            // the end user. HttpExceptions are authored with user-safe messages;
            // everything else gets a generic message.
            $message = $e instanceof HttpExceptionInterface
                ? $e->getMessage()
                : 'An unexpected error occurred.';

            try {
                return Inertia::render('Error', [
                    'status' => $status,
                    'message' => $message,
                ])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (Throwable) {
                // If Inertia rendering itself fails (e.g. Vite manifest missing,
                // Blade view cache unwritable), fall back to the default response
                // so the exception handler doesn't recurse.
                return $response;
            }
        });

        Integration::handles($exceptions);
    })->withSchedule(function (Schedule $schedule) {
        Mtgo::schedule($schedule);
    })->create();
