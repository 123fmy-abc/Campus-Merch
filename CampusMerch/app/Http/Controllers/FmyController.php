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
use App\Exports\OrdersExport;
use App\Imports\ProductsImport;
use App\Http\Requests\ImportProductRequest;
use App\Http\Requests\ReviewOrderRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockChangeLog;
use App\Services\AuditService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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
                    'role' => $user->role,
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
                    'role'    => $user->role,
                    'default_address'=> $user->default_address,
                    'avatar'  => $user->avatar,
                ],
                'token' => $token,
            ],
        ]);
    }

    /**
     * 管理员批量导入商品（Excel）
     * POST /api/admin/products/import
     * 文件格式: xlsx/xls, ≤10MB
     * 表头: name, category, type(spec), price, stock(Real_stock), cover_url, custom_rule
     */
    public function importProducts(ImportProductRequest $request)
    {
        $admin = JWTAuth::parseToken()->authenticate();
        $file = $request->file('file');

        try {
            DB::beginTransaction();

            $import = new ProductsImport();
            Excel::import($import, $file);

            // 记录审计日志
            AuditService::log(
                $admin->id,
                AuditLog::OPERATOR_ADMIN,
                Product::class,
                null,
                AuditLog::ACTION_IMPORT,
                null,
                [
                    'success_count' => $import->getSuccessCount(),
                    'fail_count'    => $import->getFailCount(),
                    'errors'        => $import->getErrors(),
                ],
                true
            );

            DB::commit();

            return response()->json([
                'code'    => 200,
                'message' => '导入完成',
                'data'    => [
                    'success_count' => $import->getSuccessCount(),
                    'fail_count'    => $import->getFailCount(),
                    'errors'        => $import->getErrors(),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            AuditService::log(
                $admin->id,
                AuditLog::OPERATOR_ADMIN,
                Product::class,
                null,
                AuditLog::ACTION_IMPORT,
                null,
                null,
                false,
                $e->getMessage()
            );

            return response()->json([
                'code'    => 500,
                'message' => '导入失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 管理员导出订单报表（Excel）
     * GET /api/admin/orders/export?status=&date_start=&date_end=&keyword=
     * 支持流式输出防OOM，含OSS临时链接生成
     */
    public function exportOrders(Request $request)
    {
        $admin = JWTAuth::parseToken()->authenticate();

        try {
            // 构建筛选查询（流式，不全量加载到内存）
            $query = Order::with(['product', 'user', 'attachments'])
                ->whereNotIn('status', ['draft', 'cancelled']);

            // 按状态筛选
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // 按时间范围筛选
            if ($request->filled('date_start')) {
                $query->where('created_at', '>=', $request->input('date_start'));
            }
            if ($request->filled('date_end')) {
                $query->where('created_at', '<=', $request->input('date_end') . ' 23:59:59');
            }

            // 关键词搜索（订单号/商品名/用户名）
            if ($request->filled('keyword')) {
                $keyword = $request->input('keyword');
                $query->where(function ($q) use ($keyword) {
                    $q->where('order_no', 'like', "%{$keyword}%")
                        ->orWhereHas('product', fn($p) => $p->where('name', 'like', "%{$keyword}%"))
                        ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$keyword}%"));
                });
            }

            // 记录审计日志
            AuditService::log(
                $admin->id,
                AuditLog::OPERATOR_ADMIN,
                Order::class,
                null,
                AuditLog::ACTION_EXPORT,
                null,
                array_filter($request->only(['status', 'date_start', 'date_end', 'keyword'])),
                true
            );

            // 流式导出（FromQuery模式，不会全量加载到内存）
            $fileName = sprintf('orders_export_%s.xlsx', date('YmdHis'));
            return Excel::download(new OrdersExport($query), $fileName);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '导出失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 管理员审核订单定制稿（通过/驳回）
     * 状态流转：
     *   design_pending --[通过]--> ready (待发货)
     *   design_pending --[驳回]--> rejected (已驳回，可重新上传)
     */
    public function reviewOrder(ReviewOrderRequest $request, $id)
    {
        $admin = JWTAuth::parseToken()->authenticate();
        $validated = $request->validated();

        try {
            $order = Order::with('product')->findOrFail($id);

            // ====== 1. 状态机校验 ======
            if (!in_array($order->status, ['design_pending', 'design_reviewing'])) {
                return response()->json([
                    'code'    => 400,
                    'message' => "当前订单状态为【{$order->status}】，不允许审核操作",
                ], 400);
            }

            // ====== 2. 幂等性检查（防止重复点击）=====
            if ($order->reviewed_at !== null && $validated['action'] === 'approve') {
                return response()->json([
                    'code'    => 409,
                    'message' => '该订单已审核，请勿重复操作',
                ], 409);
            }

            // ====== 3. 开启事务处理状态变更 + 库存联动 ======
            DB::beginTransaction();

            $oldStatus = $order->status;

            if ($validated['action'] === 'approve') {
                // 审核通过：design_pending → ready
                $order->status = 'ready';

                // 最终扣减库存：reserved_stock → real_stock 减少, sold_count 增加
                $product = $order->product;
                StockService::confirmDeduct($product, $order->quantity, Order::class, $order->id, $admin->id, '订单审核通过，最终扣减库存');

            } else {
                // 审核驳回：design_pending → rejected
                $order->status             = 'rejected';
                $order->reject_reason      = $validated['reject_reason'] ?? '';
            }

            // 更新审核信息
            $order->reviewed_by    = $admin->id;
            $order->reviewed_at    = now();
            $order->save();

            // ====== 4. 记录审计日志 ======
            AuditService::log(
                $admin->id,
                AuditLog::OPERATOR_ADMIN,
                Order::class,
                $order->id,
                AuditLog::ACTION_REVIEW,
                ['status' => $oldStatus],
                [
                    'status'        => $order->status,
                    'action'        => $validated['action'],
                    'reject_reason' => $order->reject_reason ?? null,
                    'remark'        => $validated['remark'] ?? '',
                ],
                true
            );

            DB::commit();

            $actionText = $validated['action'] === 'approve' ? '已通过' : '已驳回';
            return response()->json([
                'code'    => 200,
                "message" => "订单审核{$actionText}",
                'data'    => [
                    'order_id'   => $order->id,
                    'order_no'   => $order->order_no,
                    'status'     => $order->status,
                    'reviewed_at'=> $order->reviewed_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            AuditService::log(
                $admin->id ?? 0,
                AuditLog::OPERATOR_ADMIN,
                Order::class,
                $id,
                AuditLog::ACTION_REVIEW,
                null,
                null,
                false,
                $e->getMessage()
            );

            return response()->json([
                'code'    => 500,
                'message' => '审核失败：' . $e->getMessage(),
            ], 500);
        }
    }


    // 在 StockService.php 中追加此方法（约第123行后）

    /**
     * 最终确认扣减库存（审核通过时调用）
     * reserved_stock 释放，real_stock 减少，sold_count 增加
     */
    public static function confirmDeduct(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $freshProduct = Product::find($product->id);
            if (!$freshProduct) {
                throw new \Exception('商品不存在');
            }

            $beforeRealStock    = $freshProduct->real_stock;
            $beforeReserved     = $freshProduct->reserved_stock;
            $oldVersion         = $freshProduct->version;

            // 校验库存充足
            if ($beforeRealStock < $quantity) {
                throw new \Exception('实际库存不足，无法完成扣减');
            }
            if ($beforeReserved < $quantity) {
                throw new \Exception('预扣库存数据异常');
            }

            // 乐观锁更新：同时减少 real_stock、reserved_stock、增加 sold_count
            $updated = Product::where('id', $freshProduct->id)
                ->where('version', $oldVersion)
                ->update([
                    'real_stock'     => $beforeRealStock - $quantity,
                    'reserved_stock' => $beforeReserved - $quantity,
                    'sold_count'     => $freshProduct->sold_count + $quantity,
                    'version'        => $oldVersion + 1,
                ]);

            if ($updated) {
                StockChangeLog::create([
                    'product_id'      => $freshProduct->id,
                    'type'            => 'confirm_deduct',
                    'change_qty'      => -$quantity,
                    'stock_before'    => $beforeRealStock,
                    'reserved_before' => $beforeReserved,
                    'stock_after'     => $beforeRealStock - $quantity,
                    'reserved_after'  => $beforeReserved - $quantity,
                    'related_type'    => $relatedType,
                    'related_id'      => $relatedId,
                    'operator_id'     => $operatorId,
                    'remark'          => $remark ?? '审核通过，最终扣减库存',
                ]);
                return true;
            }

            if ($attempt == $maxRetries) {
                throw new \Exception('库存操作冲突，请重试');
            }
            usleep(50000); // 50ms 后重试
        }
        return false;
    }

    /**
     * 商品维护
     * PUT /api/admin/products/{id}
     * Body: { name?, category_id?, price?, real_stock?, status?, ... }
     */
    public function updateProduct(UpdateProductRequest $request, $id)
    {
        $admin = JWTAuth::parseToken()->authenticate();
        // 白名单过滤：只允许 rules 中声明的字段入库，防止非预期字段注入
        $validated = $request->validated();

        try {
            // 查找商品
            $product = Product::findOrFail($id);
            $originalData = $product->toArray();

            // 敏感字段变更检测（price / real_stock）
            $sensitiveFields = ['price', 'real_stock'];
            $changedSensitive = [];
            foreach ($sensitiveFields as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] != $product->$field) {
                    $changedSensitive[$field] = [
                        'old' => $product->$field,
                        'new' => $validated[$field]
                    ];
                }
            }

            // ====== 关联订单校验 ======
            // 1. 下架/归档：禁止有进行中的订单
            if (isset($validated['status']) && in_array($validated['status'], ['archived', 'draft'])) {
                $activeOrders = Order::where('product_id', $product->id)
                    ->whereIn('status', ['booked', 'design_pending', 'design_reviewing', 'ready', 'shipped'])
                    ->exists();
                if ($activeOrders) {
                    return response()->json([
                        'code'    => 400,
                        'message' => '该商品存在未完成的订单，暂时无法下架或归档',
                    ], 400);
                }
            }

            // 2. 库存调低：检查是否低于已预扣量（会导致已预订用户无法核销）
            if (isset($validated['real_stock']) && $validated['real_stock'] < $product->reserved_stock) {
                return response()->json([
                    'code'    => 400,
                    'message' => "库存不能低于已预订量（当前预扣：{$product->reserved_stock}），请先处理相关订单",
                ], 400);
            }

            // 3. 价格大幅变动：有进行中订单时给出警告提示（不拦截但记录审计）
            if (isset($validated['price']) && isset($originalData['price'])) {
                $priceDiff = abs($validated['price'] - $originalData['price']);
                $priceRatio = $originalData['price'] > 0 ? $priceDiff / $originalData['price'] : 0;
                $hasActiveOrders = Order::where('product_id', $product->id)
                    ->whereIn('status', ['booked', 'design_pending', 'design_reviewing', 'ready'])
                    ->exists();
                if ($hasActiveOrders && $priceRatio > 0.3) {
                    // 变动超30%仅记录到审计日志备注中，不阻止操作
                    $changedSensitive['_price_warning'] = "价格变动超过30%，该商品有进行中的订单";
                }
            }

            // ====== 乐观锁更新（白名单字段） ======
            DB::beginTransaction();

            $currentVersion = $product->version;
            $allowedFields = array_keys($validated); // validated 已经过 FormRequest 规则校验，天然是白名单
            $updateData = [];
            foreach ($allowedFields as $field) {
                $updateData[$field] = $validated[$field];
            }
            $updateData['version'] = $currentVersion + 1;

            $updatedRows = Product::where('id', $product->id)
                ->where('version', $currentVersion) // 乐观锁条件
                ->update($updateData);

            if (!$updatedRows) {
                DB::rollBack();
                return response()->json([
                    'code'    => 409,
                    'message' => '商品信息已被其他人修改，请刷新后重试',
                ], 409);
            }

            // 刷新模型数据
            $product->refresh();

            // 库存变更记录日志（type 值与 StockChangeLog 模型常量 TYPE_ADJUST='adjust' 保持一致）
            if (isset($validated['real_stock']) && $validated['real_stock'] != $originalData['real_stock']) {
                StockChangeLog::create([
                    'product_id'      => $product->id,
                    'type'            => StockChangeLog::TYPE_ADJUST,   // 统一使用模型常量 'adjust'
                    'change_qty'      => $validated['real_stock'] - $originalData['real_stock'],
                    'stock_before'    => $originalData['real_stock'],
                    'reserved_before' => $product->reserved_stock,
                    'stock_after'     => $product->real_stock,
                    'reserved_after'  => $product->reserved_stock,
                    'related_type'    => 'manual',
                    'related_id'      => null,
                    'operator_id'     => $admin->id,
                    'operator_type'   => StockChangeLog::OPERATOR_ADMIN,
                    'remark'          => '管理员手动调整库存',
                ]);
            }

            // 清除缓存
            Cache::forget("product:detail:{$product->id}");

            // 审计日志
            AuditService::log(
                $admin->id,
                AuditLog::OPERATOR_ADMIN,
                Product::class,
                $product->id,
                AuditLog::ACTION_UPDATE,
                $originalData,
                array_merge($product->toArray(), ['sensitive_changes' => $changedSensitive]),
                true
            );

            DB::commit();

            return response()->json([
                'code'    => 200,
                'message' => '商品信息更新成功',
                'data'    => [
                    'id'         => $product->id,
                    'name'       => $product->name,
                    'version'    => $product->version,
                    'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\ModelNotFoundException $e) {
            return response()->json([
                'code'    => 404,
                'message' => '商品不存在',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            AuditService::log(
                $admin->id ?? 0,
                AuditLog::OPERATOR_ADMIN,
                Product::class,
                $id,
                AuditLog::ACTION_UPDATE,
                null,
                null,
                false,
                $e->getMessage()
            );

            return response()->json([
                'code'    => 500,
                'message' => '更新失败：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 管理员数据看板
     * 返回指标：
     * - 今日新预订订单量
     * - 待审核（设计稿）订单数
     * - 库存预警商品列表（可用库存 <= 10）
     * - 各状态订单统计
     * - 本周销量 TOP5 商品
     */
    public function dashboardStats(Request $request)
    {
        $admin = JWTAuth::parseToken()->authenticate();

        // 缓存策略：看板数据变化频率低，60s 内复用结果减少数据库压力
        $cacheKey = 'admin:dashboard:stats';
        $cacheTtl = 60; // 秒

        $data = Cache::remember($cacheKey, $cacheTtl, function () {
            $today = today();
            $weekAgo = now()->subDays(7);

            // ====== 核心聚合指标 ======

            // 1. 今日预订量（status=booked 且创建于今日）
            $todayBookedCount = Order::whereDate('created_at', $today)
                ->where('status', 'booked')
                ->count();

            // 2. 待审核数（设计稿待审核 + 审核中）
            $pendingReviewCount = Order::whereIn('status', ['design_pending', 'design_reviewing'])
                ->count();

            // 3. 库存预警：real_stock - reserved_stock <= 10 的商品
            $lowStockProducts = Product::with('category')
                ->whereRaw('COALESCE(real_stock, 0) - COALESCE(reserved_stock, 0) <= 10')
                ->where('status', 'published')   // 仅展示在售商品
                ->orderByRaw('COALESCE(real_stock, 0) - COALESCE(reserved_stock, 0)', 'asc')
                ->select(['id', 'name', 'code', 'real_stock', 'reserved_stock', 'cover_url', 'status', 'category_id'])
                ->limit(20)
                ->get()
                ->map(function ($p) {
                    return [
                        'id'              => $p->id,
                        'name'            => $p->name,
                        'code'            => $p->code,
                        'real_stock'      => $p->real_stock,
                        'reserved_stock'  => $p->reserved_stock,
                        'available_stock' => max(0, ($p->real_stock ?? 0) - ($p->reserved_stock ?? 0)),
                        'cover_image'     => $p->cover_url,
                        'category_name'   => $p->category?->name ?? '-',
                        'is_critical'     => ($p->real_stock ?? 0) <= ($p->reserved_stock ?? 0), // 已超售
                    ];
                });

            // 4. 各状态订单数统计
            $orderStatusStats = Order::query()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // 5. 本周销量 TOP5 商品
            $topProducts = Order::whereBetween('created_at', [$weekAgo, now()])
                ->whereIn('status', ['completed', 'ready']) // 已完成或待发货的算有效销量
                ->selectRaw('product_id, SUM(quantity) as total_qty, COUNT(*) as order_count')
                ->groupBy('product_id')
                ->orderByDesc('total_qty')
                ->limit(5)
                ->get()
                ->map(function ($row) {
                    $prod = Product::find($row->product_id);
                    return [
                        'product_id'   => $row->product_id,
                        'product_name' => $prod?->name ?? '未知商品',
                        'total_qty'    => (int) $row->total_qty,
                        'order_count'  => (int) $row->order_count,
                    ];
                });

            // 6. 总览数字
            $overview = [
                'total_products'       => Product::count(),
                'published_products'   => Product::where('status', 'published')->count(),
                'archived_products'    => Product::where('status', 'archived')->count(),
                'total_orders_today'   => Order::whereDate('created_at', $today)->count(),
                'low_stock_count'      => $lowStockProducts->count(),
                'critical_stock_count' => $lowStockProducts->where('is_critical', true)->count(),
            ];

            return compact(
                'todayBookedCount',
                'pendingReviewCount',
                'lowStockProducts',
                'orderStatusStats',
                'topProducts',
                'overview'
            );
        });

        return response()->json([
            'code'    => 200,
            'message' => '获取看板数据成功',
            'data'    => $data,
            'meta'    => [
                'cached_at'    => now()->toIso8601String(),
                'cache_ttl'    => 60,
                'generated_by' => $admin->name,
            ],
        ]);
    }

}
