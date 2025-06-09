<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckActive;
use App\Http\Middleware\CheckCompanyApproved;
use App\Http\Middleware\CheckAdmin;
use App\Http\Middleware\LogApiRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Global middleware
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Alias middleware
        $middleware->alias([
            'active' => CheckActive::class,
            'company.approved' => CheckCompanyApproved::class,
            'admin' => CheckAdmin::class,
            'log.api' => LogApiRequests::class,
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);

        // Rate limiting
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Custom exception handling
        $exceptions->report(function (Throwable $e) {
            if (app()->bound('systemLog')) {
                app('systemLog')->error(
                    'application',
                    $e->getMessage(),
                    [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                    $e
                );
            }
        });

        // Render exceptions as JSON for API
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            return $request->is('api/*');
        });
    })->create();
