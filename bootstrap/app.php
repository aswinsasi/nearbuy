<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Admin routes with 'admin' prefix
            Route::middleware('web')
                ->prefix('admin')
                ->group(base_path('routes/admin.php'));
            
            // Agreement verification routes
            Route::middleware('web')
                ->group(base_path('routes/agreement_verification.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register custom middleware aliases
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'verify.whatsapp' => \App\Http\Middleware\VerifyWhatsAppWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();