<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class FmyController extends Controller
{
    /**
     * 发送 QQ 邮箱验证码
     * 请求参数:
     * - email: 目标邮箱地址 (必填)
     */
    public function sendEmailCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code'    => 422,
                'message' => '参数验证失败',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $email = $request->input('email');

        // 检查发送频率限制（60秒内只能发送一次）
        $cacheKey = "email_code_limit:{$email}";
        if (Cache::has($cacheKey)) {
            $remainingSeconds = Cache::get($cacheKey) - now()->timestamp;
            return response()->json([
                'code'    => 429,
                'message' => '发送过于频繁，请稍后再试',
                'data'    => ['wait_seconds' => max(0, $remainingSeconds)],
            ], 429);
        }

        // 生成6位数字验证码
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 验证码缓存键
        $codeCacheKey = "email_code:{$email}";

        try {
            // 发送邮件
            Mail::raw("您的验证码是：{$code}，有效期为10分钟，请勿泄露给他人。", function ($message) use ($email) {
                $message->to($email)
                    ->subject('【校园周边商城】验证码');
            });

            // 缓存验证码，有效期10分钟
            Cache::put($codeCacheKey, $code, now()->addMinutes(10));

            // 记录发送频率限制，60秒内不能重复发送
            Cache::put($cacheKey, now()->addSeconds(60)->timestamp, 60);

            return response()->json([
                'code'    => 200,
                'message' => '验证码发送成功',
                'data'    => [
                    'email'      => $email,
                    'expires_in' => 600,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '验证码发送失败，请稍后重试',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 用户注册
     * 请求参数:
     * - name: 姓名 (必填, string)
     * - email: 邮箱 (必填, string, 唯一)
     * - password: 密码 (必填, string, 6-20位)
     * - verify_code: 邮箱验证码 (必填, string, 6位)
     * - phone: 手机号 (可选, string)
     */
    public function register(RegisterRequest $request)
    {
        $email = $request->input('email');
        $verifyCode = $request->input('verify_code');

        // 验证邮箱验证码
        if (!$this->verifyEmailCode($email, $verifyCode)) {
            return response()->json([
                'code'    => 400,
                'message' => '验证码错误或已过期',
            ], 400);
        }

        try {
            $user = User::create([
                'name'     => $request->input('name'),
                'email'    => $email,
                'password' => Hash::make($request->input('password')),
                'phone'    => $request->input('phone'),
                'role'     => 1,
                'status'   => 1,
            ]);

            $token = JWTAuth::fromUser($user);

            return response()->json([
                'code'    => 200,
                'message' => '注册成功',
                'data'    => [
                    'user'         => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role'  => $user->role,
                    ],
                    'access_token' => $token,
                    'token_type'   => 'Bearer',
                    'expires_in'   => auth('api')->factory()->getTTL() * 60,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '注册失败，请稍后重试',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 重置密码
     * POST /api/reset-password
     *
     * 请求参数:
     * - email: 邮箱 (必填, string)
     * - verify_code: 邮箱验证码 (必填, string, 6位)
     * - password: 新密码 (必填, string, 6-20位)
     * - password_confirmation: 确认密码 (必填, string)
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $email = $request->input('email');
        $verifyCode = $request->input('verify_code');

        // 验证邮箱验证码
        if (!$this->verifyEmailCode($email, $verifyCode)) {
            return response()->json([
                'code'    => 400,
                'message' => '验证码错误或已过期',
            ], 400);
        }

        try {
            $user = User::where('email', $email)->first();
            $user->password = Hash::make($request->input('password'));
            $user->save();

            return response()->json([
                'code'    => 200,
                'message' => '密码重置成功',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '密码重置失败，请稍后重试',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 验证邮箱验证码
     *
     * @param string $email 邮箱地址
     * @param string $code 验证码
     * @return bool 验证是否通过
     */
    private function verifyEmailCode(string $email, string $code): bool
    {
        $cacheKey = "email_code:{$email}";
        $cachedCode = Cache::get($cacheKey);

        if (!$cachedCode || $cachedCode !== $code) {
            return false;
        }

        // 验证成功后删除缓存（一次性使用）
        Cache::forget($cacheKey);

        return true;
    }
}
