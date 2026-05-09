<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\Product;
use App\Services\OssService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ZztController - zzt 负责的接口控制器
 *
 * 包含以下 API 接口：
 *
 * 用户接口 (zzt3)：
 * - 3.1 商品大厅
 * - 3.2 商品详情
 * - 3.3 提交预订
 * - 3.4 上传定制稿
 * - 3.5 确认收货/核销
 * - 3.6 我的订单
 * - 3.7 上传支付凭证
 *
 * 分类模块 (zzt6) - 需要管理员权限：
 * - 6.1 新建分类
 * - 6.2 修改分类
 * - 6.3 删除分类
 */
class ZztController extends Controller
{
    /**
     * 3.1 商品大厅
     *
     * 获取商品列表，支持多种筛选条件
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * 查询参数：
     * - category_id: int 分类ID筛选
     * - status: string 状态筛选 (published/archived)
     * - min_price: decimal 最低价格
     * - max_price: decimal 最高价格
     * - keyword: string 关键词搜索(名称/描述)
     * - page: int 页码，默认1
     * - per_page: int 每页数量，默认15
     */
    public function productIndex(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'status' => 'nullable|string|in:published,archived',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // 构建查询
        $query = Product::with('category')
            ->select([
                'id',
                'name',
                'code',
                'description',
                'price',
                'real_stock',
                'reserved_stock',
                'sold_count',
                'cover_url',
                'need_design',
                'status',
                'category_id',
                'low_stock_threshold',
            ]);

        // 按分类筛选
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // 默认只显示已发布的商品
            $query->where('status', 'published');
        }

        // 按价格范围筛选
        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // 按关键词搜索（名称或描述）
        if ($request->has('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        // 分页查询
        $perPage = $request->input('per_page', 15);
        $products = $query->paginate($perPage);

        // 格式化响应数据
        $formattedProducts = $products->through(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'description' => $product->description,
                'price' => $product->price,
                'stock' => $product->stock,
                'reserved_qty' => $product->reserved_qty,
                'sold_qty' => $product->sold_qty,
                'available_stock' => $product->available_stock,
                'cover_image' => $product->cover_image,
                'allow_custom' => $product->allow_custom,
                'status' => $product->status,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->name,
                ] : null,
                'is_low_stock' => $product->is_low_stock,
            ];
        });

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $formattedProducts,
        ]);
    }

    /**
     * 3.2 商品详情
     *
     * 获取单个商品的详细信息，包含规格、分类、图片等
     *
     * @param int $id 商品ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function productShow($id)
    {
        // 查询商品详情，关联分类和图片
        $product = Product::with(['category', 'images'])
            ->find($id);

        // 商品不存在返回404
        if (!$product) {
            return response()->json([
                'code' => 404,
                'message' => '商品不存在',
            ], 404);
        }

        // 格式化图片数据
        $images = $product->images->map(function ($image) {
            return [
                'id' => $image->id,
                'file_url' => $image->file_url,
                'is_cover' => $image->is_cover,
                'sort_order' => $image->sort_order,
            ];
        });

        // 构建响应数据
        $data = [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'description' => $product->description,
            'specifications' => $product->specifications,
            'price' => $product->price,
            'stock' => $product->stock,
            'reserved_qty' => $product->reserved_qty,
            'sold_qty' => $product->sold_qty,
            'available_stock' => $product->available_stock,
            'cover_image' => $product->cover_image,
            'custom_rule' => $product->custom_rule,
            'allow_custom' => $product->allow_custom,
            'status' => $product->status,
            'version' => $product->version,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
            ] : null,
            'images' => $images,
        ];

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * 3.3 提交预订
     *
     * 用户提交商品预订订单
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - product_id: int 商品ID（必填）
     * - quantity: int 预订数量（必填，≥1）
     * - size: string 尺寸规格（可选）
     * - color: string 颜色偏好（可选）
     * - remark: string 备注（可选）
     * - recipient_name: string 收货人姓名（必填）
     * - recipient_phone: string 收货人电话（必填）
     * - recipient_address: string 收货地址（必填）
     */
    public function orderStore(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'size' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'remark' => 'nullable|string|max:500',
            'recipient_name' => 'required|string|max:100',
            'recipient_phone' => 'required|string|max:20',
            'recipient_address' => 'required|string|max:255',
        ]);

        // 获取商品信息
        $product = Product::find($validated['product_id']);

        // 检查商品是否可预订
        if ($product->status !== 'published') {
            return response()->json([
                'code' => 400,
                'message' => '该商品已下架，无法预订',
            ], 400);
        }

        // 检查库存是否充足
        if ($product->available_stock < $validated['quantity']) {
            return response()->json([
                'code' => 400,
                'message' => '库存不足',
                'data' => [
                    'available_stock' => $product->available_stock,
                    'requested_quantity' => $validated['quantity'],
                ],
            ], 400);
        }

        // 使用数据库事务确保数据一致性
        try {
            $order = DB::transaction(function () use ($request, $validated, $product) {
                // 预扣库存
                $product->reserved_stock += $validated['quantity'];
                $product->save();

                // 生成订单号：年月日 + 4位序号
                $orderNo = date('Ymd') . str_pad(
                    Order::whereDate('created_at', today())->count() + 1,
                    4,
                    '0',
                    STR_PAD_LEFT
                );

                // 计算订单总金额
                $totalAmount = $product->price * $validated['quantity'];

                // 创建订单
                $order = Order::create([
                    'order_no' => $orderNo,
                    'user_id' => $request->user()->id,
                    'product_id' => $validated['product_id'],
                    'quantity' => $validated['quantity'],
                    'unit_price' => $product->price,
                    'total_amount' => $totalAmount,
                    'size' => $validated['size'] ?? null,
                    'color' => $validated['color'] ?? null,
                    'remark' => $validated['remark'] ?? null,
                    'status' => 'booked',
                    'submitted_at' => now(),
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'recipient_address' => $validated['recipient_address'],
                ]);

                return $order;
            });

            // 加载关联数据
            $order->load('product');

            return response()->json([
                'code' => 200,
                'message' => '预订成功',
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_no' => $order->order_no,
                        'product_id' => $order->product_id,
                        'quantity' => $order->quantity,
                        'unit_price' => $order->unit_price,
                        'total_amount' => $order->total_amount,
                        'size' => $order->size,
                        'color' => $order->color,
                        'remark' => $order->remark,
                        'status' => $order->status,
                        'submitted_at' => $order->submitted_at,
                        'recipient_name' => $order->recipient_name,
                        'recipient_phone' => $order->recipient_phone,
                        'recipient_address' => $order->recipient_address,
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '预订失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3.4 上传定制稿
     *
     * 为订单上传设计稿文件
     *
     * @param Request $request
     * @param int $id 订单ID
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - design_file: file 设计稿文件（必填，jpg/png/pdf/ai/psd，≤15MB）
     */
    public function uploadDesign(Request $request, $id)
    {
        // 验证上传文件
        $validated = $request->validate([
            'design_file' => 'required|file|mimes:jpg,jpeg,png,pdf,ai,psd|max:15360',
        ]);

        // 查询订单
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        // 订单不存在或无权限
        if (!$order) {
            return response()->json([
                'code' => 404,
                'message' => '订单不存在',
            ], 404);
        }

        // 检查订单状态是否允许上传设计稿
        if (!in_array($order->status, ['booked', 'design_pending'])) {
            return response()->json([
                'code' => 400,
                'message' => '当前订单状态不允许上传设计稿',
                'data' => [
                    'current_status' => $order->status,
                    'allowed_statuses' => ['booked', 'design_pending'],
                ],
            ], 400);
        }

        try {
            // 获取上传文件
            $file = $request->file('design_file');
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();

            // 生成唯一文件名
            $uniqueName = date('YmdHis') . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();

            // OSS 存储路径
            $ossObject = "merch-designs/{$order->id}/{$uniqueName}";

            // 上传到阿里云 OSS
            $ossService = new OssService();
            $uploadResult = $ossService->uploadFile($ossObject, $file->getRealPath());

            if (!$uploadResult['success']) {
                return response()->json([
                    'code' => 500,
                    'message' => '文件上传失败',
                    'error' => $uploadResult['error'],
                ], 500);
            }

            $fileUrl = $uploadResult['url'];

            // 获取图片尺寸（如果是图片）
            $width = null;
            $height = null;
            if (str_starts_with($mimeType, 'image/')) {
                $imageInfo = getimagesize($file->getRealPath());
                if ($imageInfo) {
                    $width = $imageInfo[0];
                    $height = $imageInfo[1];
                }
            }

            // 创建附件记录
            $attachment = OrderAttachment::create([
                'order_id' => $order->id,
                'file_name' => $fileName,
                'file_url' => $fileUrl,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'type' => 'design',
                'width' => $width,
                'height' => $height,
            ]);

            // 更新订单状态为待审核设计稿
            $order->status = 'design_pending';
            $order->design_uploaded_at = now();
            $order->save();

            return response()->json([
                'code' => 200,
                'message' => '上传成功',
                'data' => [
                    'attachment' => [
                        'id' => $attachment->id,
                        'order_id' => $attachment->order_id,
                        'file_name' => $attachment->file_name,
                        'file_url' => $attachment->file_url,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'width' => $attachment->width,
                        'height' => $attachment->height,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'status' => $order->status,
                        'design_uploaded_at' => $order->design_uploaded_at,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '上传失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3.5 确认收货/核销
     *
     * 用户确认已收到商品，完成订单
     *
     * @param Request $request
     * @param int $id 订单ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function completeOrder(Request $request, $id)
    {
        // 查询订单
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        // 订单不存在
        if (!$order) {
            return response()->json([
                'code' => 404,
                'message' => '订单不存在',
            ], 404);
        }

        // 检查订单状态是否允许确认收货
        // 只有 ready(待发货) 或 shipped(已发货) 状态可以确认收货
        if (!in_array($order->status, ['ready', 'shipped'])) {
            return response()->json([
                'code' => 400,
                'message' => '当前订单状态不允许确认收货',
                'data' => [
                    'current_status' => $order->status,
                    'allowed_statuses' => ['ready', 'shipped'],
                ],
            ], 400);
        }

        try {
            DB::transaction(function () use ($order) {
                // 更新订单状态为已完成
                $order->status = 'completed';
                $order->completed_at = now();
                $order->save();

                // 更新商品销量
                $product = $order->product;
                if ($product) {
                    $product->sold_count += $order->quantity;
                    $product->reserved_stock -= $order->quantity;
                    $product->save();
                }
            });

            return response()->json([
                'code' => 200,
                'message' => '确认收货成功',
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'order_no' => $order->order_no,
                        'status' => $order->status,
                        'completed_at' => $order->completed_at,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '操作失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3.6 我的订单
     *
     * 获取当前用户的订单列表
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * 查询参数：
     * - status: string 状态筛选
     * - page: int 页码
     * - per_page: int 每页数量
     */
    public function myOrders(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'status' => 'nullable|string|in:booked,design_pending,design_reviewing,ready,shipped,completed,cancelled',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // 构建查询
        $query = Order::with('product')
            ->where('user_id', $request->user()->id);

        // 按状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // 按创建时间倒序排列
        $query->orderBy('created_at', 'desc');

        // 分页查询
        $perPage = $request->input('per_page', 15);
        $orders = $query->paginate($perPage);

        // 状态文本映射
        $statusTextMap = [
            'booked' => '已预订',
            'design_pending' => '待上传设计稿',
            'design_reviewing' => '设计稿审核中',
            'ready' => '待发货',
            'shipped' => '已发货',
            'completed' => '已完成',
            'cancelled' => '已取消',
        ];

        // 格式化响应数据
        $formattedOrders = $orders->through(function ($order) use ($statusTextMap) {
            return [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'product' => $order->product ? [
                    'id' => $order->product->id,
                    'name' => $order->product->name,
                    'cover_image' => $order->product->cover_image,
                ] : null,
                'quantity' => $order->quantity,
                'total_amount' => $order->total_amount,
                'status' => $order->status,
                'status_text' => $statusTextMap[$order->status] ?? $order->status,
                'submitted_at' => $order->submitted_at,
            ];
        });

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $formattedOrders,
        ]);
    }

    /**
     * 3.7 上传支付凭证
     *
     * 为订单上传支付凭证截图
     *
     * @param Request $request
     * @param int $id 订单ID
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - payment_proof: file 支付凭证截图（必填，jpg/png，≤5MB）
     */
    public function uploadPaymentProof(Request $request, $id)
    {
        // 验证上传文件
        $validated = $request->validate([
            'payment_proof' => 'required|file|mimes:jpg,jpeg,png|max:5120',
        ]);

        // 查询订单
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        // 订单不存在
        if (!$order) {
            return response()->json([
                'code' => 404,
                'message' => '订单不存在',
            ], 404);
        }

        // 检查订单状态是否允许上传支付凭证
        if (!in_array($order->status, ['booked', 'design_pending'])) {
            return response()->json([
                'code' => 422,
                'message' => '订单状态不允许上传',
                'data' => [
                    'current_status' => $order->status,
                    'allowed_statuses' => ['booked', 'design_pending'],
                ],
            ], 422);
        }

        try {
            // 获取上传文件
            $file = $request->file('payment_proof');
            $fileName = $file->getClientOriginalName();
            $fileSize = $file->getSize();
            $mimeType = $file->getMimeType();

            // 生成唯一文件名
            $uniqueName = date('YmdHis') . '_' . Str::random(6) . '.' . $file->getClientOriginalExtension();

            // OSS 存储路径
            $ossObject = "payment-proofs/{$order->id}/{$uniqueName}";

            // 上传到阿里云 OSS
            $ossService = new OssService();
            $uploadResult = $ossService->uploadFile($ossObject, $file->getRealPath());

            if (!$uploadResult['success']) {
                return response()->json([
                    'code' => 500,
                    'message' => '文件上传失败',
                    'error' => $uploadResult['error'],
                ], 500);
            }

            $fileUrl = $uploadResult['url'];

            // 获取图片尺寸
            $imageInfo = getimagesize($file->getRealPath());
            $width = $imageInfo[0] ?? null;
            $height = $imageInfo[1] ?? null;

            // 创建附件记录
            $attachment = OrderAttachment::create([
                'order_id' => $order->id,
                'file_name' => $fileName,
                'file_url' => $fileUrl,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'type' => 'payment_proof',
                'width' => $width,
                'height' => $height,
            ]);

            // 更新订单支付信息
            $order->payment_proof_url = $fileUrl;
            $order->paid_at = now();
            $order->save();

            return response()->json([
                'code' => 200,
                'message' => '支付凭证上传成功',
                'data' => [
                    'attachment' => [
                        'id' => $attachment->id,
                        'order_id' => $attachment->order_id,
                        'type' => $attachment->type,
                        'file_name' => $attachment->file_name,
                        'file_url' => $attachment->file_url,
                        'mime_type' => $attachment->mime_type,
                        'file_size' => $attachment->file_size,
                        'width' => $attachment->width,
                        'height' => $attachment->height,
                        'created_at' => $attachment->created_at,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'status' => $order->status,
                        'payment_proof_url' => $order->payment_proof_url,
                        'paid_at' => $order->paid_at,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '上传失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3.8 取消订单
     *
     * 用户取消已提交的订单，释放预扣库存
     *
     * @param Request $request
     * @param int $id 订单ID
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - cancel_reason: string 取消原因（可选，max:255）
     */
    public function cancelOrder(Request $request, $id)
    {
        // 验证请求参数
        $validated = $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        // 查询订单
        $order = Order::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        // 订单不存在
        if (!$order) {
            return response()->json([
                'code' => 404,
                'message' => '订单不存在',
            ], 404);
        }

        // 检查订单状态是否允许取消
        // 只允许取消 booked(已预订) 或 design_pending(待上传设计稿) 状态的订单
        if (!in_array($order->status, ['booked', 'design_pending'])) {
            return response()->json([
                'code' => 400,
                'message' => '订单状态不允许取消',
                'data' => null,
                'errors' => [
                    'status' => "当前订单状态为 {$order->status}，仅允许取消已预订或待审核的订单",
                ],
            ], 400);
        }

        try {
            DB::transaction(function () use ($order, $validated, $request) {
                // 释放预扣库存
                $product = $order->product;
                if ($product) {
                    $product->reserved_stock -= $order->quantity;
                    $product->save();
                }

                // 更新订单状态为已取消
                $order->status = 'cancelled';
                $order->cancel_reason = $validated['cancel_reason'] ?? null;
                $order->cancelled_at = now();
                $order->cancelled_by = $request->user()->id;
                $order->save();
            });

            return response()->json([
                'code' => 200,
                'message' => '订单取消成功',
                'data' => [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'status' => $order->status,
                    'cancel_reason' => $order->cancel_reason,
                    'cancelled_at' => $order->cancelled_at,
                    'cancelled_by' => $order->cancelled_by,
                    'released_stock' => $order->quantity,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '取消失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== zzt6. 分类模块（需要管理员权限） ====================

    /**
     * 6.1 新建分类
     *
     * 管理员创建商品分类
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - name: string 分类名称（必填，min:2, max:50, unique）
     * - status: integer 状态（可选，0-禁用，1-启用，默认1）
     * - sort_order: integer 排序权重（可选，默认0）
     */
    public function storeCategory(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:50|unique:categories,name',
            'status' => 'nullable|integer|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            // 创建分类
            $category = Category::create([
                'name' => $validated['name'],
                'status' => $validated['status'] ?? 1,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            return response()->json([
                'code' => 200,
                'message' => '分类创建成功',
                'data' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'status' => $category->status,
                    'sort_order' => $category->sort_order,
                    'created_at' => $category->created_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '创建失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 6.2 修改分类
     *
     * 管理员修改商品分类信息，禁用分类将自动隐藏该分类下所有商品
     *
     * @param Request $request
     * @param int $id 分类ID
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - name: string 分类名称（可选，min:2, max:50, unique）
     * - status: integer 状态（可选，0-禁用，1-启用）
     * - sort_order: integer 排序权重（可选，min:0）
     */
    public function updateCategory(Request $request, $id)
    {
        // 查询分类
        $category = Category::find($id);

        // 分类不存在
        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在',
            ], 404);
        }

        // 验证请求参数
        $validated = $request->validate([
            'name' => 'nullable|string|min:2|max:50|unique:categories,name,' . $id,
            'status' => 'nullable|integer|in:0,1',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            // 记录原始状态，用于判断是否需要更新商品
            $originalStatus = $category->status;
            $newStatus = $validated['status'] ?? $originalStatus;

            // 更新分类信息
            if (isset($validated['name'])) {
                $category->name = $validated['name'];
            }
            if (isset($validated['status'])) {
                $category->status = $validated['status'];
            }
            if (isset($validated['sort_order'])) {
                $category->sort_order = $validated['sort_order'];
            }
            $category->save();

            // 如果禁用分类，自动隐藏该分类下所有商品
            $affectedProducts = 0;
            $operation = 'updated';

            if ($originalStatus == 1 && $newStatus == 0) {
                // 从启用变为禁用，隐藏商品
                $affectedProducts = Product::where('category_id', $category->id)
                    ->where('status', 'published')
                    ->update(['status' => 'archived']);
                $operation = 'disabled';
            } elseif ($originalStatus == 0 && $newStatus == 1) {
                // 从禁用变为启用，显示商品
                $affectedProducts = Product::where('category_id', $category->id)
                    ->where('status', 'archived')
                    ->update(['status' => 'published']);
                $operation = 'enabled';
            }

            // 构建响应消息
            $message = '分类更新成功';
            if ($operation === 'disabled' && $affectedProducts > 0) {
                $message = "分类禁用成功，该分类下{$affectedProducts}个商品已自动隐藏";
            }

            return response()->json([
                'code' => 200,
                'message' => $message,
                'data' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'status' => $category->status,
                    'sort_order' => $category->sort_order,
                    'affected_products' => $affectedProducts,
                    'operation' => $operation,
                    'updated_at' => $category->updated_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '更新失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 6.3 删除分类
     *
     * 管理员删除商品分类，该分类下必须没有商品
     *
     * @param int $id 分类ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroyCategory($id)
    {
        // 查询分类
        $category = Category::find($id);

        // 分类不存在
        if (!$category) {
            return response()->json([
                'code' => 404,
                'message' => '分类不存在',
            ], 404);
        }

        // 检查分类下是否存在商品
        $productCount = Product::where('category_id', $id)->count();
        if ($productCount > 0) {
            return response()->json([
                'code' => 400,
                'message' => '无法删除分类',
                'data' => null,
                'errors' => [
                    'products' => "该分类下还有 {$productCount} 个商品，请先删除商品或转移分类后再删除",
                ],
            ], 400);
        }

        try {
            // 删除分类
            $deletedAt = now();
            $category->delete();

            return response()->json([
                'code' => 200,
                'message' => '分类删除成功',
                'data' => [
                    'id' => (int) $id,
                    'deleted_at' => $deletedAt,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '删除失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 6.4 创建商品（管理员）
     *
     * 管理员创建新商品
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     *
     * 请求参数：
     * - name: string 商品名称（必填，唯一）
     * - code: string 商品编码（必填，唯一）
     * - description: string 商品描述（可选）
     * - price: numeric 商品价格（必填）
     * - category_id: integer 分类ID（必填）
     * - real_stock: integer 实际库存（必填）
     * - max_buy_limit: integer 每人限购数量（可选，默认0）
     * - status: string 状态（可选，draft/published/archived，默认published）
     */
    public function storeProduct(Request $request)
    {
        // 验证请求参数
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:products,name',
            'code' => 'required|string|max:50|unique:products,code',
            'description' => 'nullable|string|max:1000',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|integer|exists:categories,id',
            'real_stock' => 'required|integer|min:0',
            'max_buy_limit' => 'nullable|integer|min:0',
            'status' => 'nullable|string|in:draft,published,archived',
        ]);

        try {
            $product = Product::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'category_id' => $validated['category_id'],
                'real_stock' => $validated['real_stock'],
                'reserved_stock' => 0,
                'sold_count' => 0,
                'max_buy_limit' => $validated['max_buy_limit'] ?? 0,
                'status' => $validated['status'] ?? 'published',
                'need_design' => false,
                'version' => 0,
            ]);

            return response()->json([
                'code' => 200,
                'message' => '商品创建成功',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'description' => $product->description,
                    'price' => $product->price,
                    'category_id' => $product->category_id,
                    'real_stock' => $product->real_stock,
                    'status' => $product->status,
                    'created_at' => $product->created_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '创建失败，请稍后重试',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
