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
        // الطلبات تصل عبر بروكسي عكسي على الشبكة الخاصة فقط؛ لا نثق برؤوس
        // X-Forwarded-* إلا منه، حتى لا يستطيع عميل خارجي تزييف عنوانه أو المضيف.
        // يمكن تجاوز القائمة بـ TRUSTED_PROXIES في بيئة نشر مختلفة.
        $middleware->trustProxies(
            at: array_filter(array_map(
                'trim',
                explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'))
            )),
            headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

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
