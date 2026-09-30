<?php

use App\Http\Middleware\EnsureSetupCompleted;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'setup.completed' => EnsureSetupCompleted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // أخطاء منطق الأعمال تعود للواجهة كرسالة عربية مفهومة، لا كصفحة 500
        $exceptions->render(function (DomainException $e, Illuminate\Http\Request $request) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['message' => $e->getMessage()])->withInput();
        });
    })->create();
