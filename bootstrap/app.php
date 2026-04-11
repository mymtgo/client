<?php

use App\Facades\Mtgo;
use App\Http\Middleware\EnsureDebugMode;
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
        ]);
        $middleware->alias([
            'debug' => EnsureDebugMode::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface
                && in_array($response->getStatusCode(), [403, 404, 419, 500, 503])
            ) {
                return Inertia::render('Error', [
                    'status' => $response->getStatusCode(),
                    'message' => $e->getMessage(),
                ])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->withSchedule(function (Schedule $schedule) {
        Mtgo::schedule($schedule);
    })->withExceptions(function (Exceptions $exceptions) {
        Integration::handles($exceptions);
    })->create();
