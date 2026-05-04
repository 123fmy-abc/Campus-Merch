<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '无权访问，需要管理员权限',
            ], 403);
        }

        return $next($request);
    }
}
