<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZztController;

/*
|--------------------------------------------------------------------------
| Web Routes - zzt 负责的用户接口路由
|--------------------------------------------------------------------------
|
| 包含以下 API 接口：
| - 3.1 商品大厅 (GET /api/products)
| - 3.2 商品详情 (GET /api/products/{id})
| - 3.3 提交预订 (POST /api/orders)
| - 3.4 上传定制稿 (POST /api/orders/{id}/design)
| - 3.5 确认收货/核销 (POST /api/orders/{id}/complete)
| - 3.6 我的订单 (GET /api/my-orders)
* - 3.7 上传支付凭证 (POST /api/orders/{id}/payment-proof)
 * - 3.8 取消订单 (POST /api/orders/{id}/cancel)
 *
 * 分类模块 (zzt6)：
 * - 6.1 新建分类 (POST /api/admin/categories)
 * - 6.2 修改分类 (PUT /api/admin/categories/{id})
 * - 6.3 删除分类 (DELETE /api/admin/categories/{id})
 *
 */

// API 前缀
Route::prefix('api')->group(function () {

    // 需要认证的路由
    Route::middleware('auth:sanctum')->group(function () {

        // ==================== zzt3. 用户接口 ====================

        // 3.1 商品大厅 - 获取商品列表，支持筛选
        // GET /api/products?category_id=1&min_price=50&keyword=文化衫
        Route::get('/products', [ZztController::class, 'productIndex']);

        // 3.2 商品详情 - 获取单个商品详细信息
        // GET /api/products/{id}
        Route::get('/products/{id}', [ZztController::class, 'productShow']);

        // 3.3 提交预订 - 创建新订单
        // POST /api/orders
        Route::post('/orders', [ZztController::class, 'orderStore']);

        // 3.4 上传定制稿 - 为订单上传设计稿文件
        // POST /api/orders/{id}/design
        Route::post('/orders/{id}/design', [ZztController::class, 'uploadDesign']);

        // 3.5 确认收货/核销 - 用户确认收到商品
        // POST /api/orders/{id}/complete
        Route::post('/orders/{id}/complete', [ZztController::class, 'completeOrder']);

        // 3.6 我的订单 - 获取当前用户的订单列表
        // GET /api/my-orders?status=booked&page=1
        Route::get('/my-orders', [ZztController::class, 'myOrders']);

        // 3.7 上传支付凭证 - 为订单上传支付截图
        // POST /api/orders/{id}/payment-proof
        Route::post('/orders/{id}/payment-proof', [ZztController::class, 'uploadPaymentProof']);

        // 3.8 取消订单 - 用户取消订单，释放库存
        // POST /api/orders/{id}/cancel
        Route::post('/orders/{id}/cancel', [ZztController::class, 'cancelOrder']);

        // ==================== zzt6. 分类模块（需要管理员权限） ====================

        // 6.1 新建分类 - 管理员创建商品分类
        // POST /api/admin/categories
        Route::middleware('admin')->post('/admin/categories', [ZztController::class, 'storeCategory']);

        // 6.2 修改分类 - 管理员修改商品分类信息
        // PUT /api/admin/categories/{id}
        Route::middleware('admin')->put('/admin/categories/{id}', [ZztController::class, 'updateCategory']);

        // 6.3 删除分类 - 管理员删除商品分类
        // DELETE /api/admin/categories/{id}
        Route::middleware('admin')->delete('/admin/categories/{id}', [ZztController::class, 'destroyCategory']);
    });
});
