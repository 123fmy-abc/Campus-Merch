<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SingleSessionMiddleware;
use Illuminate\Auth\AuthenticationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // 覆盖默认的未认证重定向，API 返回 401 JSON（不重定向）
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? '/api/unauthenticated' : '/login');
        
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'single.session' => SingleSessionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => '请先登录或提供有效Token',
                    'code' => 401
                ], 401);
            }
        });
    })->create();
