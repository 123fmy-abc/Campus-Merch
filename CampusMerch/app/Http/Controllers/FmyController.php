<?php

namespace App\Http\Controllers;

use App\Exports\OrdersExport;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Imports\ProductsImport;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockChangeLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException;
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

    /**
     * 用户注册
     * 请求参数:
     * - name: 姓名 (必填, string)
     * - email: 邮箱 (必填, string, 唯一)
     * - password: 密码 (必填, string, 6-20位)
     * - password_confirmed: 验证密码
     * - code: 邮箱验证码 (必填, string, 6位)
     * - phone: 手机号 (可选, string)
     */
    public function register(RegisterRequest $request)
    {
        // 1. 获取已验证的数据（FormRequest 自动验证）
        $validated = $request->validated();

        // 2. 验证邮箱验证码
        $cacheCode = cache()->get('email_code:' . $validated['email']);
        if (!$cacheCode || $cacheCode !== $request->input('code')) {
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

    /**
     * 忘记密码
     * 请求参数:
     * - email: 邮箱 (必填, string, 唯一)
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        //忘记密码
        $email = $request->validated()['email'];

        // 生成6位数字重置码
        $resetCode = random_int(100000, 999999);

        // 存入缓存，有效期10分钟
        cache()->put('password_reset_' . $email, $resetCode, 600);

        // 发送邮件
        try {
            Mail::raw("您的密码重置验证码是：{$resetCode}，10分钟内有效，请勿泄露给他人。如非本人操作，请忽略此邮件。", function ($message) use ($email) {
                $message->to($email)
                    ->subject('校园周边商城 - 密码重置');
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

    /**
     * 重置密码
     * 请求参数:
     * - email: 邮箱 (必填, string, 唯一)
     * - code: 邮箱验证码 (必填, string, 6位)
     * - password: 密码 (必填, string, 6-20位)
     * - password_confirmed: 验证密码
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $validated = $request->validated();

        // 1. 验证重置码
        $cacheCode = cache()->get('password_reset_' . $validated['email']);
        if (!$cacheCode || $cacheCode !== $validated['code']) {
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
     * 批量导入商品（Excel）- 使用 Laravel Excel
     * 请求参数:
     * - file: Excel文件 (必填, xlsx/xls/csv)
     * - update_existing: 是否更新已存在商品 (可选, boolean, 默认true)
     *
     * Excel模板列: name, category_name, code, description, specifications,
     *              price, stock, cover_url, custom_rule, need_design, status, max_buy_limit
     */
    public function importProducts(Request $request)
    {
        // 1. 权限校验 - 仅管理员可访问
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问，仅管理员可导入商品',
            ], 403);
        }

        // 2. 文件基础校验
        $validator = Validator::make($request->all(), [
            'file'            => 'required|file|mimes:xlsx,xls,csv|max:5120',
            'update_existing' => 'boolean',
        ], [
            'file.required' => '请上传Excel文件',
            'file.file'     => '上传必须是文件',
            'file.mimes'    => '文件格式必须是 xlsx, xls 或 csv',
            'file.max'      => '文件大小不能超过5MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code'    => 422,
                'message' => '参数验证失败',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $file = $request->file('file');
        $updateExisting = $request->input('update_existing', true);

        try {
            // 3. 使用 Laravel Excel 导入
            $import = new ProductsImport($updateExisting);
            Excel::import($import, $file);

            // 4. 获取导入结果
            $result = $import->getResult();

            // 5. 构造响应
            $hasErrors = !empty($result['errors']);
            $responseCode = ($hasErrors && $result['success_count'] > 0) ? 207 : ($hasErrors ? 422 : 200);

            if ($result['success_count'] === 0 && $hasErrors) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Excel中没有有效的商品数据',
                    'data'    => $result,
                ], 422);
            }

            $message = $hasErrors
                ? "导入完成，成功 {$result['success_count']} 条，失败 " . count($result['errors']) . " 条"
                : "成功导入 {$result['success_count']} 条商品数据";

            return response()->json([
                'code'    => $responseCode,
                'message' => $message,
                'data'    => $result,
            ], $responseCode);

        } catch (ValidationException $e) {
            // Laravel Excel 验证失败
            $failures = $e->failures();
            $errors = [];

            foreach ($failures as $failure) {
                $errors[] = [
                    'row'    => $failure->row(),
                    'field'  => $failure->attribute(),
                    'reason' => implode(', ', $failure->errors()),
                ];
            }

            return response()->json([
                'code'    => 422,
                'message' => 'Excel数据验证失败',
                'data'    => [
                    'total_rows'    => count($errors),
                    'success_count' => 0,
                    'fail_count'    => count($errors),
                    'errors'        => $errors,
                ],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '导入失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 报表导出 - 订单数据导出为Excel
     * 按当前筛选条件导出 Excel 报表（含订单明细、定制稿链接、发货地址）
     * 请求参数（筛选条件）:
     * - status: 订单状态 (可选, draft/booked/design_pending/ready/completed/rejected/cancelled)
     * - status_in: 多状态筛选 (可选, 数组)
     * - user_id: 用户ID (可选)
     * - product_id: 商品ID (可选)
     * - order_no: 订单编号模糊搜索 (可选)
     * - submitted_from: 提交开始时间 (可选, Y-m-d H:i:s)
     * - submitted_to: 提交结束时间 (可选, Y-m-d H:i:s)
     * - created_from: 创建开始时间 (可选, Y-m-d H:i:s)
     * - created_to: 创建结束时间 (可选, Y-m-d H:i:s)
     * - min_amount: 最小金额 (可选)
     * - max_amount: 最大金额 (可选)
     * - columns: 指定导出列 (可选, 数组, 默认导出全部)
     */
    public function exportOrders(Request $request)
    {
        // 1. 权限校验 - 仅管理员可访问
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问，仅管理员可导出报表',
            ], 403);
        }

        try {
            // 2. 收集筛选条件
            $filters = $request->only([
                'status', 'status_in', 'user_id', 'product_id', 'order_no',
                'submitted_from', 'submitted_to', 'created_from', 'created_to',
                'min_amount', 'max_amount'
            ]);

            // 处理数组参数
            if ($request->has('status_in')) {
                $filters['status_in'] = $request->input('status_in');
            }

            // 3. 处理导出列配置
            $columns = $request->input('columns', []);
            if (is_string($columns)) {
                $columns = explode(',', $columns);
            }

            // 4. 创建导出实例
            $export = new OrdersExport($filters, $columns);

            // 5. 生成文件名
            $filename = 'orders_export_' . date('Ymd_His') . '.xlsx';

            // 6. 流式下载（使用 Laravel Excel 的 download 方法，自动处理流式输出）
            return Excel::download($export, $filename);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '导出失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 获取订单导出可用的列定义
     * GET /api/orders/export/columns
     */
    public function getExportColumns(Request $request)
    {
        // 权限校验
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问',
            ], 403);
        }

        $export = new OrdersExport();

        return response()->json([
            'code'    => 200,
            'message' => '获取成功',
            'data'    => $export->getAvailableColumns(),
        ]);
    }

    /**
     * 订单审核
     * 请求参数:
     * - action: 审核动作 (必填, approve-通过/reject-驳回/modify-修改)
     * - quantity: 修改后的数量 (可选, 仅action=modify时有效)
     * - status: 修改后的状态 (可选, 仅action=modify时有效)
     * - review_remark: 审核备注/驳回原因 (可选)
     * - idempotency_key: 幂等性密钥 (可选, 防止重复提交)
     */
    public function reviewOrder(Request $request, $id)
    {
        // 1. 权限校验 - 仅管理员可访问
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问，仅管理员可审核订单',
            ], 403);
        }

        // 2. 参数校验
        $validator = Validator::make($request->all(), [
            'action'          => 'required|in:approve,reject,modify',
            'quantity'        => 'nullable|integer|min:1|max:9999',
            'status'          => 'nullable|in:booked,design_pending,ready,completed,rejected',
            'review_remark'   => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:64',
        ], [
            'action.required' => '审核动作不能为空',
            'action.in'       => '审核动作必须是 approve/reject/modify 之一',
            'quantity.integer'=> '数量必须是整数',
            'quantity.min'    => '数量至少为1',
            'status.in'       => '状态值不合法',
            'review_remark.max' => '审核备注不能超过500字符',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code'    => 422,
                'message' => '参数验证失败',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $action = $request->input('action');
        $idempotencyKey = $request->input('idempotency_key');

        // 3. 幂等性检查
        if ($idempotencyKey) {
            $cacheKey = "order_review:{$id}:{$idempotencyKey}";
            if (Cache::has($cacheKey)) {
                return response()->json([
                    'code'    => 409,
                    'message' => '该审核请求已处理，请勿重复提交',
                    'data'    => Cache::get($cacheKey),
                ], 409);
            }
        }

        // 4. 开启事务 + 查询订单（带锁，防止并发）
        DB::beginTransaction();

        try {
            $order = Order::with(['product', 'user'])->lockForUpdate()->find($id);
            if (!$order) {
                DB::rollBack();
                return response()->json([
                    'code'    => 404,
                    'message' => '订单不存在',
                ], 404);
            }

            // 记录审核前数据
            $beforeData = [
                'status'   => $order->status,
                'quantity' => $order->quantity,
            ];

            // 5. 根据动作处理
            $result = match ($action) {
                'approve' => $this->handleApprove($order, $request, $user),
                'reject'  => $this->handleReject($order, $request, $user),
                'modify'  => $this->handleModify($order, $request, $user),
            };

            DB::commit();

            // 6. 记录审计日志（事务外，避免回滚丢失日志）
            $afterData = [
                'status'   => $order->fresh()->status,
                'quantity' => $order->fresh()->quantity,
            ];

            AuditService::log(
                userId: $user->id,
                operatorType: \App\Models\AuditLog::OPERATOR_ADMIN,
                targetType: 'Order',
                targetId: $order->id,
                action: \App\Models\AuditLog::ACTION_REVIEW,
                before: $beforeData,
                after: $afterData,
            );

            // 7. 缓存幂等性结果（5分钟）
            if ($idempotencyKey) {
                Cache::put($cacheKey, $result, now()->addMinutes(5));
            }

            return response()->json([
                'code'    => 200,
                'message' => $result['message'],
                'data'    => $result['data'],
            ]);

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            // 状态流转不合法
            return response()->json([
                'code'    => 422,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            // 记录失败日志
            AuditService::log(
                userId: $user->id,
                operatorType: AuditLog::OPERATOR_ADMIN,
                targetType: 'Order',
                targetId: $order->id,
                action: AuditLog::ACTION_REVIEW,
                before: $beforeData,
                success: false,
                errorMsg: $e->getMessage()
            );

            return response()->json([
                'code'    => 500,
                'message' => '审核处理失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 处理审核通过
     */
    private function handleApprove(Order $order, Request $request, $user): array
    {
        // 状态流转校验：只允许从 design_pending -> ready
        if ($order->status !== 'design_pending') {
            throw new \InvalidArgumentException(
                "当前状态 '{$order->status}' 不允许审核通过，只能从 'design_pending' 状态通过"
            );
        }

        // 更新订单状态
        $order->status = 'ready';
        $order->reviewed_by = $user->id;
        $order->reviewed_at = now();
        $order->reject_reason = null; // 清除之前的驳回原因

        if ($request->filled('review_remark')) {
            $order->review_remark = $request->input('review_remark');
        }

        $order->save();

        // 库存处理：预扣库存转为实际库存扣减
        // ready状态表示可以发货，此时将reserved_stock转为sold_count
        if ($order->product) {
            $product = $order->product;
            $product->reserved_stock -= $order->quantity;
            $product->sold_count += $order->quantity;
            $product->save();

            // 记录库存变动日志
           StockChangeLog::create([
                'product_id'       => $product->id,
                'type'             => StockChangeLog::TYPE_DEDUCT,
                'change_qty'       => $order->quantity,
                'stock_before'     => $product->real_stock,
                'reserved_before'  => $product->reserved_stock + $order->quantity,
                'stock_after'      => $product->real_stock,
                'reserved_after'   => $product->reserved_stock,
                'related_id'       => $order->id,
                'related_type'     => 'order',
                'operator_id'      => $user->id,
                'operator_type'    => StockChangeLog::OPERATOR_ADMIN,
                'remark'           => '订单审核通过，预扣转实际售出',
            ]);
        }

        return [
            'message' => '订单审核通过成功',
            'data'    => [
                'order_id'     => $order->id,
                'order_no'     => $order->order_no,
                'status'       => $order->status,
                'reviewed_at'  => $order->reviewed_at->format('Y-m-d H:i:s'),
                'reviewer_name'=> $user->name,
            ],
        ];
    }

    /**
     * 处理审核驳回
     */
    private function handleReject(Order $order, Request $request, $user): array
    {
        // 状态流转校验：只允许从 design_pending -> rejected
        if ($order->status !== 'design_pending') {
            throw new \InvalidArgumentException(
                "当前状态 '{$order->status}' 不允许驳回，只能从 'design_pending' 状态驳回"
            );
        }

        // 驳回原因必填
        if (!$request->filled('review_remark')) {
            throw new \InvalidArgumentException('驳回时必须填写驳回原因');
        }

        // 更新订单状态
        $order->status = 'rejected';
        $order->reviewed_by = $user->id;
        $order->reviewed_at = now();
        $order->reject_reason = $request->input('review_remark');
        $order->review_remark = $request->input('review_remark');
        $order->save();

        // 库存处理：释放预扣库存
        if ($order->product) {
            $product = $order->product;
            $product->reserved_stock -= $order->quantity;
            $product->save();

            // 记录库存变动日志
            StockChangeLog::create([
                'product_id'       => $product->id,
                'type'             => StockChangeLog::TYPE_RELEASE,
                'change_qty'       => -$order->quantity,
                'stock_before'     => $product->real_stock,
                'reserved_before'  => $product->reserved_stock + $order->quantity,
                'stock_after'      => $product->real_stock,
                'reserved_after'   => $product->reserved_stock,
                'related_id'       => $order->id,
                'related_type'     => 'order',
                'operator_id'      => $user->id,
                'operator_type'    => StockChangeLog::OPERATOR_ADMIN,
                'remark'           => '订单审核驳回，释放预扣库存',
            ]);
        }

        return [
            'message' => '订单已驳回',
            'data'    => [
                'order_id'      => $order->id,
                'order_no'      => $order->order_no,
                'status'        => $order->status,
                'reject_reason' => $order->reject_reason,
                'reviewed_at'   => $order->reviewed_at->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * 处理订单修改
     */
    private function handleModify(Order $order, Request $request, $user): array
    {
        // 只允许修改特定状态的订单
        $allowModifyStatuses = ['booked', 'design_pending'];
        if (!in_array($order->status, $allowModifyStatuses)) {
            throw new \InvalidArgumentException(
                "当前状态 '{$order->status}' 不允许修改，只允许修改 " . implode(', ', $allowModifyStatuses) . ' 状态的订单'
            );
        }

        $oldQuantity = $order->quantity;
        $oldStatus = $order->status;
        $quantityChanged = false;
        $statusChanged = false;

        // 修改数量
        if ($request->filled('quantity')) {
            $newQuantity = $request->input('quantity');

            if ($newQuantity !== $oldQuantity) {
                // 检查库存是否充足
                if ($order->product) {
                    $availableStock = $order->product->real_stock - $order->product->reserved_stock + $oldQuantity;
                    if ($availableStock < $newQuantity) {
                        throw new \InvalidArgumentException(
                            "库存不足，当前可用库存: {$availableStock}，请求数量: {$newQuantity}"
                        );
                    }

                    // 调整预扣库存
                    $diff = $newQuantity - $oldQuantity;
                    $order->product->reserved_stock += $diff;
                    $order->product->save();

                    // 记录库存变动
                    \App\Models\StockChangeLog::create([
                        'product_id'       => $order->product->id,
                        'type'             => $diff > 0 ? \App\Models\StockChangeLog::TYPE_RESERVE : \App\Models\StockChangeLog::TYPE_RELEASE,
                        'change_qty'       => $diff,
                        'stock_before'     => $order->product->real_stock,
                        'reserved_before'  => $order->product->reserved_stock - $diff,
                        'stock_after'      => $order->product->real_stock,
                        'reserved_after'   => $order->product->reserved_stock,
                        'related_id'       => $order->id,
                        'related_type'     => 'order',
                        'operator_id'      => $user->id,
                        'operator_type'    => \App\Models\StockChangeLog::OPERATOR_ADMIN,
                        'remark'           => "管理员修改订单数量: {$oldQuantity} -> {$newQuantity}",
                    ]);
                }

                $order->quantity = $newQuantity;
                $order->total_amount = $order->unit_price * $newQuantity;
                $quantityChanged = true;
            }
        }

        // 修改状态
        if ($request->filled('status')) {
            $newStatus = $request->input('status');

            if ($newStatus !== $oldStatus) {
                // 状态流转校验
                $this->validateStatusTransition($oldStatus, $newStatus);

                // 如果状态变为 rejected，需要释放库存
                if ($newStatus === 'rejected' && $order->product) {
                    $order->product->reserved_stock -= $order->quantity;
                    $order->product->save();

                    \App\Models\StockChangeLog::create([
                        'product_id'       => $order->product->id,
                        'type'             => \App\Models\StockChangeLog::TYPE_RELEASE,
                        'change_qty'       => -$order->quantity,
                        'stock_before'     => $order->product->real_stock,
                        'reserved_before'  => $order->product->reserved_stock + $order->quantity,
                        'stock_after'      => $order->product->real_stock,
                        'reserved_after'   => $order->product->reserved_stock,
                        'related_id'       => $order->id,
                        'related_type'     => 'order',
                        'operator_id'      => $user->id,
                        'operator_type'    => \App\Models\StockChangeLog::OPERATOR_ADMIN,
                        'remark'           => '管理员修改订单状态为驳回，释放库存',
                    ]);
                }

                $order->status = $newStatus;
                $statusChanged = true;
            }
        }

        // 修改审核备注
        if ($request->filled('review_remark')) {
            $order->review_remark = $request->input('review_remark');
        }

        $order->reviewed_by = $user->id;
        $order->reviewed_at = now();
        $order->save();

        return [
            'message' => '订单修改成功',
            'data'    => [
                'order_id'         => $order->id,
                'order_no'         => $order->order_no,
                'status'           => $order->status,
                'status_changed'   => $statusChanged,
                'quantity'         => $order->quantity,
                'quantity_changed' => $quantityChanged,
                'total_amount'     => $order->total_amount,
                'reviewed_at'      => $order->reviewed_at->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * 验证状态流转是否合法
     */
    private function validateStatusTransition(string $from, string $to): void
    {
        // 定义合法的状态流转
        $validTransitions = [
            'booked'         => ['design_pending', 'rejected', 'cancelled'],
            'design_pending' => ['ready', 'rejected', 'cancelled'],
            'ready'          => ['completed', 'cancelled'],
            'rejected'       => [], // 终态，不可流转
            'completed'      => [], // 终态，不可流转
            'cancelled'      => [], // 终态，不可流转
        ];

        if (!isset($validTransitions[$from]) || !in_array($to, $validTransitions[$from])) {
            throw new \InvalidArgumentException(
                "非法的状态流转: '{$from}' -> '{$to}'"
            );
        }
    }

    /**
     * 商品维护
     * 请求参数:
     * - name: 商品名称 (可选)
     * - category_id: 分类ID (可选)
     * - description: 商品描述 (可选)
     * - specifications: 规格参数JSON (可选)
     * - price: 单价 (可选)
     * - real_stock: 实际库存 (可选)
     * - cover_url: 封面图URL (可选)
     * - custom_rule: 定制规则JSON (可选)
     * - need_design: 是否需要设计稿 (可选, boolean)
     * - status: 状态 (可选, draft/published/archived)
     * - max_buy_limit: 限购数量 (可选)
     * - version: 当前版本号 (必填, 乐观锁)
     * - change_reason: 变更原因 (可选, 用于敏感字段变更日志)
     */
    public function updateProduct(Request $request, $id)
    {
        // 1. 权限校验 - 仅管理员可访问
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问，仅管理员可修改商品',
            ], 403);
        }

        // 2. 参数校验
        $validator = Validator::make($request->all(), [
            'name'            => 'nullable|string|max:255',
            'category_id'     => 'nullable|integer|exists:categories,id',
            'description'     => 'nullable|string|max:2000',
            'specifications'  => 'nullable',
            'price'           => 'nullable|numeric|min:0|max:99999999.99',
            'real_stock'      => 'nullable|integer|min:0|max:999999999',
            'cover_url'       => 'nullable|string|max:500',
            'custom_rule'     => 'nullable',
            'need_design'     => 'nullable|boolean',
            'status'          => 'nullable|in:draft,published,archived',
            'max_buy_limit'   => 'nullable|integer|min:1|max:9999',
            'version'         => 'required|integer|min:0',
            'change_reason'   => 'nullable|string|max:500',
        ], [
            'name.max'              => '商品名称不能超过255字符',
            'category_id.exists'    => '所选分类不存在',
            'description.max'       => '商品描述不能超过2000字符',
            'price.numeric'         => '价格必须是数字',
            'price.min'             => '价格不能为负数',
            'real_stock.integer'    => '库存必须是整数',
            'real_stock.min'        => '库存不能为负数',
            'status.in'             => '状态必须是 draft/published/archived 之一',
            'max_buy_limit.min'     => '限购数量至少为1',
            'version.required'      => '版本号不能为空（乐观锁）',
            'version.integer'       => '版本号必须是整数',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code'    => 422,
                'message' => '参数验证失败',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // 3. 查询商品
        $product = Product::find($id);
        if (!$product) {
            return response()->json([
                'code'    => 404,
                'message' => '商品不存在',
            ], 404);
        }

        // 4. 乐观锁校验
        $inputVersion = (int) $request->input('version');
        if ($inputVersion !== $product->version) {
            return response()->json([
                'code'    => 409,
                'message' => '数据已被其他用户修改，请刷新后重试',
                'data'    => [
                    'current_version' => $product->version,
                    'your_version'    => $inputVersion,
                ],
            ], 409);
        }

        // 5. 关联订单校验
        $hasActiveOrders = $this->checkActiveOrders($product);

        // 如果有进行中的订单，限制某些敏感字段的修改
        $restrictedFields = [];
        if ($hasActiveOrders) {
            $restrictedFields = $this->getRestrictedFields($request);
            if (!empty($restrictedFields)) {
                return response()->json([
                    'code'    => 422,
                    'message' => '该商品存在进行中的订单，无法修改以下字段: ' . implode(', ', $restrictedFields),
                    'data'    => [
                        'restricted_fields' => $restrictedFields,
                        'active_orders_count' => $this->getActiveOrdersCount($product),
                    ],
                ], 422);
            }
        }

        // 6. 收集变更数据
        $updateData = [];
        $sensitiveChanges = []; // 敏感字段变更记录
        $beforeData = []; // 变更前数据
        $afterData = [];  // 变更后数据

        // 基础字段
        $fields = [
            'name', 'category_id', 'description', 'specifications',
            'price', 'real_stock', 'cover_url', 'custom_rule',
            'need_design', 'status', 'max_buy_limit'
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $newValue = $request->input($field);
                $oldValue = $product->$field;

                // 检查是否有实际变更
                if ($this->isValueChanged($oldValue, $newValue)) {
                    $updateData[$field] = $newValue;
                    $beforeData[$field] = $oldValue;
                    $afterData[$field] = $newValue;

                    // 记录敏感字段变更
                    if (in_array($field, ['price', 'real_stock'])) {
                        $sensitiveChanges[$field] = [
                            'from' => $oldValue,
                            'to'   => $newValue,
                        ];
                    }
                }
            }
        }

        // 如果没有实际变更
        if (empty($updateData)) {
            return response()->json([
                'code'    => 200,
                'message' => '没有需要更新的数据',
                'data'    => [
                    'product_id' => $product->id,
                    'version'    => $product->version,
                ],
            ]);
        }

        // 7. 执行更新（带乐观锁）
        try {
            DB::beginTransaction();

            // 使用乐观锁更新：version + 1，并校验version
            $updateData['version'] = $product->version + 1;

            $updated = \App\Models\Product::where('id', $id)
                ->where('version', $product->version)
                ->update($updateData);

            if (!$updated) {
                DB::rollBack();
                return response()->json([
                    'code'    => 409,
                    'message' => '数据已被其他用户修改，请刷新后重试',
                    'data'    => [
                        'current_version' => \App\Models\Product::find($id)->version ?? null,
                        'your_version'    => $product->version,
                    ],
                ], 409);
            }

            // 8. 记录敏感字段变更日志
            if (!empty($sensitiveChanges)) {
                $changeReason = $request->input('change_reason', '管理员修改商品');

                foreach ($sensitiveChanges as $field => $change) {
                    \App\Services\AuditService::log(
                        userId: $user->id,
                        operatorType: \App\Models\AuditLog::OPERATOR_ADMIN,
                        targetType: 'Product',
                        targetId: $product->id,
                        action: \App\Models\AuditLog::ACTION_UPDATE,
                        before: ['field' => $field, 'value' => $change['from']],
                        after: ['field' => $field, 'value' => $change['to'], 'reason' => $changeReason],
                        success: true,
                        errorMsg: null
                    );
                }
            }

            // 9. 记录一般变更日志
            if (!empty($beforeData) && empty($sensitiveChanges)) {
                \App\Services\AuditService::log(
                    userId: $user->id,
                    operatorType: \App\Models\AuditLog::OPERATOR_ADMIN,
                    targetType: 'Product',
                    targetId: $product->id,
                    action: \App\Models\AuditLog::ACTION_UPDATE,
                    before: $beforeData,
                    after: $afterData,
                    success: true,
                    errorMsg: null
                );
            }

            DB::commit();

            // 10. 返回更新后的数据
            $updatedProduct = \App\Models\Product::find($id);

            return response()->json([
                'code'    => 200,
                'message' => '商品更新成功',
                'data'    => [
                    'product_id'         => $updatedProduct->id,
                    'name'               => $updatedProduct->name,
                    'version'            => $updatedProduct->version,
                    'updated_fields'     => array_keys($updateData),
                    'sensitive_changes'  => $sensitiveChanges,
                    'has_active_orders'  => $hasActiveOrders,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            // 记录失败日志
            AuditService::log(
                userId: $user->id,
                operatorType: AuditLog::OPERATOR_ADMIN,
                targetType: 'Product',
                targetId: $product->id,
                action: AuditLog::ACTION_UPDATE,
                before: $beforeData,
                success: false,
                errorMsg: $e->getMessage()
            );

            return response()->json([
                'code'    => 500,
                'message' => '商品更新失败: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 检查商品是否有进行中的订单
     */
    private function checkActiveOrders(Product $product): bool
    {
        return $product->orders()
            ->whereIn('status', ['booked', 'design_pending', 'ready'])
            ->exists();
    }

    /**
     * 获取进行中订单数量
     */
    private function getActiveOrdersCount(Product $product): int
    {
        return $product->orders()
            ->whereIn('status', ['booked', 'design_pending', 'ready'])
            ->count();
    }

    /**
     * 获取被限制的字段列表
     */
    private function getRestrictedFields(Request $request): array
    {
        // 有进行中的订单时，不允许修改的字段
        $restricted = ['price', 'specifications', 'need_design'];

        $requestedRestricted = [];
        foreach ($restricted as $field) {
            if ($request->has($field)) {
                $requestedRestricted[] = $field;
            }
        }

        return $requestedRestricted;
    }

    /**
     * 判断值是否发生变更
     */
    private function isValueChanged($old, $new): bool
    {
        // JSON字段特殊处理
        if (is_array($new) || is_object($new)) {
            $new = json_encode($new);
        }
        if (is_array($old) || is_object($old)) {
            $old = json_encode($old);
        }

        return (string) $old !== (string) $new;
    }

    /**
     * 数据看板 - 系统核心指标统计
     * 返回指标:
     * - 今日预订量、金额
     * - 待审核订单数
     * - 待发货订单数
     * - 库存预警商品列表
     * - 总用户数、商品数
     * - 订单状态分布
     */
    public function getDashboardStats(Request $request)
    {
        // 1. 权限校验 - 仅管理员可访问
        $user = auth('api')->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'code'    => 403,
                'message' => '无权访问，仅管理员可查看数据看板',
            ], 403);
        }

        try {
            // 2. 尝试从缓存获取
            $cacheKey = 'admin:dashboard:stats';
            $cached = Cache::get($cacheKey);

            if ($cached && !$request->boolean('refresh')) {
                return response()->json([
                    'code'    => 200,
                    'message' => '获取成功（来自缓存）',
                    'data'    => $cached,
                ]);
            }

            // 3. 计算今日日期范围
            $today = now()->startOfDay();
            $tomorrow = now()->addDay()->startOfDay();

            // 4. 聚合查询 - 今日预订统计
            $todayStats = Order::where('created_at', '>=', $today)
                ->where('created_at', '<', $tomorrow)
                ->whereNotIn('status', ['cancelled'])
                ->selectRaw('
                    COUNT(*) as order_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    COALESCE(SUM(quantity), 0) as total_quantity
                ')
                ->first();

            // 5. 聚合查询 - 订单状态分布
            $statusDistribution = Order::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // 6. 待审核订单数（design_pending 状态）
            $pendingReviewCount = $statusDistribution['design_pending'] ?? 0;

            // 7. 待发货订单数（ready 状态）
            $pendingShipCount = $statusDistribution['ready'] ?? 0;

            // 8. 库存预警商品（可用库存 < 10 或 预扣库存 > 可用库存）
            $stockWarningProducts = Product::where('status', 'published')
                ->where(function ($query) {
                    $query->whereRaw('real_stock - reserved_stock < 10')
                        ->orWhereRaw('reserved_stock > real_stock - reserved_stock');
                })
                ->select('id', 'name', 'code', 'real_stock', 'reserved_stock')
                ->selectRaw('real_stock - reserved_stock as available_stock')
                ->orderBy('available_stock', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($product) {
                    return [
                        'id'              => $product->id,
                        'name'            => $product->name,
                        'code'            => $product->code,
                        'real_stock'      => $product->real_stock,
                        'reserved_stock'  => $product->reserved_stock,
                        'available_stock' => $product->available_stock,
                        'warning_level'   => $product->available_stock <= 0 ? 'critical' : 'warning',
                    ];
                });

            // 9. 基础数据统计
            $totalUsers = \App\Models\User::count();
            $totalProducts = Product::count();
            $totalOrders = Order::count();

            // 10. 近7天订单趋势
            $last7Days = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->format('Y-m-d');
                $startOfDay = now()->subDays($i)->startOfDay();
                $endOfDay = now()->subDays($i)->addDay()->startOfDay();

                $dayStats = Order::where('created_at', '>=', $startOfDay)
                    ->where('created_at', '<', $endOfDay)
                    ->whereNotIn('status', ['cancelled'])
                    ->selectRaw('
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_amount), 0) as total_amount
                    ')
                    ->first();

                $last7Days[] = [
                    'date'         => $date,
                    'order_count'  => (int) $dayStats->order_count,
                    'total_amount' => (float) $dayStats->total_amount,
                ];
            }

            // 11. 组装数据
            $stats = [
                'today' => [
                    'order_count'   => (int) $todayStats->order_count,
                    'total_amount'  => (float) $todayStats->total_amount,
                    'total_quantity'=> (int) $todayStats->total_quantity,
                ],
                'pending' => [
                    'review_count' => $pendingReviewCount,
                    'ship_count'   => $pendingShipCount,
                    'total'        => $pendingReviewCount + $pendingShipCount,
                ],
                'stock_warning' => [
                    'count'    => $stockWarningProducts->count(),
                    'products' => $stockWarningProducts,
                ],
                'overview' => [
                    'total_users'    => $totalUsers,
                    'total_products' => $totalProducts,
                    'total_orders'   => $totalOrders,
                ],
                'order_status_distribution' => [
                    'draft'          => $statusDistribution['draft'] ?? 0,
                    'booked'         => $statusDistribution['booked'] ?? 0,
                    'design_pending' => $statusDistribution['design_pending'] ?? 0,
                    'ready'          => $statusDistribution['ready'] ?? 0,
                    'completed'      => $statusDistribution['completed'] ?? 0,
                    'rejected'       => $statusDistribution['rejected'] ?? 0,
                    'cancelled'      => $statusDistribution['cancelled'] ?? 0,
                ],
                'trend_last_7_days' => $last7Days,
                'cached_at'         => now()->toDateTimeString(),
            ];

            // 12. 写入缓存（5分钟）
            Cache::put($cacheKey, $stats, now()->addMinutes(5));

            return response()->json([
                'code'    => 200,
                'message' => '获取成功',
                'data'    => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '获取统计数据失败: ' . $e->getMessage(),
            ], 500);
        }
    }

}
