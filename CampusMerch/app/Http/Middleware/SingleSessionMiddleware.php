<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SingleSessionMiddleware
{
    /**
     * 单点登录验证中间件
     * 确保用户只能在一个设备登录
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // 获取当前 token
            $token = JWTAuth::getToken();
            
            if (!$token) {
                return response()->json([
                    'code'    => 401,
                    'message' => '未提供认证令牌',
                ], 401);
            }

            // 解析 token 获取用户
            $user = JWTAuth::authenticate($token);
            
            if (!$user) {
                return response()->json([
                    'code'    => 401,
                    'message' => '用户不存在',
                ], 401);
            }

            // 获取缓存中的有效 token
            $cachedToken = Cache::get('user_token:' . $user->id);
            
            // 如果缓存中没有 token 或当前 token 与缓存不匹配，说明已在别处登录
            if (!$cachedToken || $cachedToken !== (string) $token) {
                return response()->json([
                    'code'    => 401,
                    'message' => '账号已在其他设备登录，请重新登录',
                ], 401);
            }

            return $next($request);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException $e) {
            return response()->json([
                'code'    => 401,
                'message' => '登录已过期，请重新登录',
            ], 401);
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException $e) {
            return response()->json([
                'code'    => 401,
                'message' => '无效的认证令牌',
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'code'    => 401,
                'message' => '认证失败：' . $e->getMessage(),
            ], 401);
        }
    }
}