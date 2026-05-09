<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class FmyController extends Controller
{
    /**1
     * 发送 QQ 邮箱验证码
     * 请求参数:
     * - email: 目标邮箱地址 (必填)
     */
    public function sendEmailCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|regex:/^\d+@qq\.com$/',
        ], [
            'email.required' => '邮箱不能为空',
            'email.email'    => '邮箱格式不正确',
            'email.regex'    => '只能使用QQ邮箱（格式：QQ号@qq.com）',
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

    /**2
     * 用户注册
     * 请求参数:
     * - name: 姓名 (必填, string)
     * - email: 邮箱 (必填, string, 唯一)
     * - password: 密码 (必填, string, 6-20位)
     * -password_confirmed:验证密码
     * - code: 邮箱验证码 (必填, string, 6位)
     * - phone: 手机号 (可选, string)
     */
    public function register(RegisterRequest $request)
    {
        // 1. 获取已验证的数据（FormRequest 自动验证）
        $validated = $request->validated();

        // 2. 验证邮箱验证码
        $cacheCode = cache()->get('email_code:' . $validated['email']);
        if (!$cacheCode || $cacheCode != $request->input('code')) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期',
                'data' => []
            ], 400);
        }

        // 3. 创建用户（密码加密存储，role 默认为 user）
        $user = User::create([
            'account' => $validated['account'],
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'user',
            'email_verified_at' => now()
        ]);

        // 4. 注册成功后删除验证码（防止重复使用）
        cache()->forget('email_code:' . $validated['email']);

        // 5. 返回成功响应（不返回Token，需要重新登录）
        return response()->json([
            'code' => 200,
            'message' => '注册成功，请使用账号密码登录',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'account' => $user->account,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                ]
            ]
        ]);
    }

    /**3
     * 忘记密码
     * 请求参数:
     * - email: 邮箱 (必填, string, 唯一)
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        //忘记密码
        $email = $request->validated()['email'];

        // 生成6位数字重置码
        $resetCode = rand(100000, 999999);

        // 存入缓存，有效期10分钟
        cache()->put('password_reset_' . $email, $resetCode, 600);

        // 发送邮件
        try {
            Mail::raw("您的密码重置验证码是：{$resetCode}，10分钟内有效，请勿泄露给他人。如非本人操作，请忽略此邮件。", function ($message) use ($email) {
                $message->to($email)
                    ->subject('实验室设备系统 - 密码重置');
            });

            return response()->json([
                'code' => 200,
                'message' => '重置验证码已发送至您的邮箱，请查收',
                'data' => []
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '邮件发送失败：' . $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    /**4
     * 重置密码
     * 请求参数:
     * - email: 邮箱 (必填, string, 唯一)
     * - code: 邮箱验证码 (必填, string, 6位)
     * -password: 密码 (必填, string, 6-20位)
     * -password_confirmed:验证密码
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $validated = $request->validated();

        // 1. 验证重置码
        $cacheCode = cache()->get('password_reset_' . $validated['email']);
        if (!$cacheCode || $cacheCode != $validated['code']) {
            return response()->json([
                'code' => 400,
                'message' => '验证码错误或已过期',
                'data' => []
            ], 400);
        }

        // 2. 查找用户
        $user = User::where('email', $validated['email'])->first();
        if (!$user) {
            return response()->json([
                'code' => 404,
                'message' => '用户不存在',
                'data' => []
            ], 404);
        }

        // 3. 更新密码
        $user->update([
            'password' => Hash::make($validated['password'])
        ]);

        // 4. 删除缓存的重置码
        cache()->forget('password_reset_' . $validated['email']);

        // 5. 返回成功响应
        return response()->json([
            'code' => 200,
            'message' => '密码重置成功，请使用新密码登录',
            'data' => []
        ]);
    }

    /**
     * 用户登录
     * 请求参数:
     * - name: 用户名 (必填, string)
     * - password: 密码 (必填, string)
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        // 查找用户
        $user = User::where('name', $validated['name'])->first();

        if (!$user) {
            return response()->json([
                'code'    => 404,
                'message' => '用户不存在',
            ], 404);
        }

        // 验证密码
        if (!Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'code'    => 401,
                'message' => '密码错误',
            ], 401);
        }

        // 生成 JWT Token
        $token = JWTAuth::fromUser($user);
        // 单点登录：将当前 token 与用户绑定，新登录会覆盖旧 token
        Cache::put('user_token:' . $user->id, $token, now()->addDays(7));

        return response()->json([
            'code'    => 200,
            'message' => '登录成功',
            'data'    => [
                'user'         => [
                    'id'      => $user->id,
                    'account' => $user->account,
                    'name'    => $user->name,
                    'email'   => $user->email,
                    'phone'   => $user->phone,
                    'default_address'=> $user->default_address,
                    'avatar'  => $user->avatar,
                ],
                'token' => $token,
            ],
        ]);
    }

}
