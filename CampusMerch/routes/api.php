<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CgjController;
use App\Http\Controllers\FmyController;
use App\Http\Controllers\ZztController;

// ========== fmy 负责的接口==========
Route::post('/verify-code/send', [FmyController::class, 'sendEmailCode']);
Route::post('/register', [FmyController::class, 'register']);
Route::post('/login', [FmyController::class, 'login']);
Route::post('/password/forgot', [FmyController::class, 'forgotPassword']);
Route::post('/password/reset', [FmyController::class, 'resetPassword']);
Route::middleware(['auth:api', 'single.session', 'admin'])->prefix('admin')->group(function () {
    //批量上架
    Route::post('/products/import', [FmyController::class, 'importProducts']);
    //报表导出
    Route::get('/orders/export', [FmyController::class, 'exportOrders']);
    //订单审核
    Route::put('/orders/{id}/review', [FmyController::class, 'reviewOrder']);
    //商品维护
    Route::put('/products/{id}', [FmyController::class, 'updateProduct']);
    //数据看板
    Route::get('/stats', [FmyController::class, 'dashboardStats']);
});




// ========== zzt 负责的接口 ==========

// 需要认证的路由
Route::middleware(['auth:api', 'single.session'])->group(function () {

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

// 需要认证的接口（使用 JWT guard）
Route::middleware(['auth:api','single.session'])->group(function () {
    // 个人中心：修改密码（PUT请求）
    Route::put('/user/password', [CgjController::class, 'updatePassword']);
    // 个人中心：修改邮箱（PUT请求）
    Route::put('/user/email', [CgjController::class, 'updateEmail']);
    // 个人中心：修改个人资料（PUT请求）
    Route::put('/user/profile', [CgjController::class, 'updateProfile']);
    // 个人中心：上传头像（POST请求）
    Route::post('/user/avatar', [CgjController::class, 'uploadAvatar']);
    // 个人中心：注销账号（DELETE请求）
    Route::delete('/user/account', [CgjController::class, 'destroyAccount']);
    // 个人中心：用户登出（POST请求）
    Route::post('/logout', [CgjController::class, 'logout']);
    // 取消订单：普通用户取消自己的订单（POST请求）
    Route::post('/orders/{id}/cancel', [CgjController::class, 'cancelOrder']);
});

// 商品图片管理（管理员）- 同样使用 auth:api
Route::prefix('admin')->middleware(['auth:api', 'single.session','admin'])->group(function () {
    // 管理员：上传商品图片（POST请求）
    Route::post('/products/{productId}/images', [CgjController::class, 'uploadProductImage']);
    // 管理员：修改商品图片（PUT请求）
    Route::put('/products/{productId}/images/{imageId}', [CgjController::class, 'updateProductImage']);
    // 管理员：删除商品图片（DELETE请求）
    Route::delete('/products/{productId}/images/{imageId}', [CgjController::class, 'deleteProductImage']);
});
