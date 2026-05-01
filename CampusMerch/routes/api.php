<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CgjController;


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
