<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CgjController;
use App\Http\Controllers\ZztController;

// ========== zzt 负责的接口 ==========

// 需要认证的路由
Route::middleware('auth:sanctum')->group(function () {

    // ==================== zzt3. 用户接口 ====================

    /**
     * 3.1 商品大厅 - 获取商品列表，支持筛选
     * GET /api/products?category_id=1&min_price=50&keyword=文化衫
     */
    Route::get('/products', [ZztController::class, 'productIndex']);

    /**
     * 3.2 商品详情 - 获取单个商品详细信息
     * GET /api/products/{id}
     */
    Route::get('/products/{id}', [ZztController::class, 'productShow']);

    /**
     * 3.3 提交预订 - 创建新订单
     * POST /api/orders
     */
    Route::post('/orders', [ZztController::class, 'orderStore']);

    /**
     * 3.4 上传定制稿 - 为订单上传设计稿文件
     * POST /api/orders/{id}/design
     */
    Route::post('/orders/{id}/design', [ZztController::class, 'uploadDesign']);

    /**
     * 3.5 确认收货/核销 - 用户确认收到商品
     * POST /api/orders/{id}/complete
     */
    Route::post('/orders/{id}/complete', [ZztController::class, 'completeOrder']);

    /**
     * 3.6 我的订单 - 获取当前用户的订单列表
     * GET /api/my-orders?status=booked&page=1
     */
    Route::get('/my-orders', [ZztController::class, 'myOrders']);

    /**
     * 3.7 上传支付凭证 - 为订单上传支付截图
     * POST /api/orders/{id}/payment-proof
     */
    Route::post('/orders/{id}/payment-proof', [ZztController::class, 'uploadPaymentProof']);

    /**
     * 3.8 取消订单 - 用户取消订单，释放库存
     * POST /api/orders/{id}/cancel
     */
    Route::post('/orders/{id}/cancel', [ZztController::class, 'cancelOrder']);

    // ==================== zzt6. 分类模块（需要管理员权限） ====================

    /**
     * 6.1 新建分类 - 管理员创建商品分类
     * POST /api/admin/categories
     */
    Route::middleware('admin')->post('/admin/categories', [ZztController::class, 'storeCategory']);

    /**
     * 6.2 修改分类 - 管理员修改商品分类信息
     * PUT /api/admin/categories/{id}
     */
    Route::middleware('admin')->put('/admin/categories/{id}', [ZztController::class, 'updateCategory']);

    /**
     * 6.3 删除分类 - 管理员删除商品分类
     * DELETE /api/admin/categories/{id}
     */
    Route::middleware('admin')->delete('/admin/categories/{id}', [ZztController::class, 'destroyCategory']);
});


// ========== cgj 负责的接口 ==========

// 需要 JWT 认证的接口组
Route::middleware(['auth:api'])->group(function () {

    Route::post('/logout', [CgjController::class, 'logout']);
    // ========== 个人中心模块 ==========
    // 获取当前登录用户的个人信息
    Route::get('user/profile', [CgjController::class, 'getProfile']);

    // 更新用户个人信息（姓名、电话、院系、默认地址）
    Route::put('user/profile', [CgjController::class, 'updateProfile']);

    // 修改用户密码（需提供旧密码和新密码）
    Route::post('user/password', [CgjController::class, 'changePassword']);

    // 上传用户头像（支持 jpg/png，最大 5MB）
    Route::post('user/avatar', [CgjController::class, 'uploadAvatar']);

    // ========== 取消订单模块 ==========

    // 取消指定订单（仅限"已预订"或"定制待审"状态的订单
    // 取消指定订单（仅限“已预订”或“定制待审”状态的订单）
    Route::put('orders/{id}/cancel', [CgjController::class, 'cancelOrder']);

    // ========== 商品图片管理模块（仅管理员） ==========
    // 为指定商品上传图片（支持多图，可标记是否为主图）
    Route::post('products/{productId}/images', [CgjController::class, 'uploadProductImage']);

    // 删除指定商品图片（物理删除文件及数据库记录）
    Route::delete('product-images/{id}', [CgjController::class, 'deleteProductImage']);

    // 将指定图片设为该商品的主图（其他图片自动取消主图标记）
    Route::put('product-images/{id}/main', [CgjController::class, 'setMainImage']);

    // 调整商品图片的显示排序（数值越小越靠前）
    Route::put('product-images/{id}/sort', [CgjController::class, 'sortProductImage']);
});
