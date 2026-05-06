<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\UploadProductImageRequest;
use App\Services\StockService;
use App\Services\AuditService;
use App\Services\FileService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
// 必须引入 Laravel 的基础 Controller 类
use Illuminate\Routing\Controller;

class CgjController extends Controller
{
    /**
     * 构造函数中应用 JWT 认证中间件
     */
    public function __construct()
    {
        $this->middleware('auth:api', [
            'except' => []  // 全部需要认证
        ]);
    }

    /**
     * 退出登录
     * POST /api/logout
     */
    public function logout()
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        JWTAuth::invalidate(JWTAuth::getToken());

        AuditService::log($user->id, 'User', 'User', $user->id, 'logout', null, null, true);

        return response()->json(['code' => 0, 'message' => '已退出登录']);
    }


    /**
     * 1. 个人中心 - 获取当前用户信息
     * GET /api/user/profile
     */
    public function getProfile()
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'id' => $user->id,
                'account' => $user->account,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'department' => $user->department,
                'avatar' => $user->avatar ? asset('storage/' . $user->avatar) : null,
                'default_address' => $user->default_address,
                'role' => $user->role,
            ]
        ]);
    }

    /**
     * 2. 个人中心 - 更新用户信息
     * PUT /api/user/profile
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        /** @var \App\Models\User|null $user */

        $user = Auth::user();
        $oldData = $user->only(['name', 'phone', 'department', 'default_address']);

        $user->fill($request->only(['name', 'phone', 'department', 'default_address']));
        $user->save();

        AuditService::log(
            $user->id, 'User', 'User', $user->id, 'update_profile',
            $oldData, $user->only(['name', 'phone', 'department', 'default_address']),
            true
        );

        return response()->json([
            'code' => 0,
            'message' => '更新成功',
            'data' => $user->only(['name', 'phone', 'department', 'default_address'])
        ]);
    }

    /**
     * 3. 个人中心 - 修改密码
     * POST /api/user/password
     */
    public function changePassword(\Illuminate\Http\Request $request)
    {
        /** @var \App\Models\User|null $user */

        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        $user = Auth::user();
        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json(['code' => 1, 'message' => '原密码错误'], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        AuditService::log($user->id, 'User', 'User', $user->id, 'change_password', null, null, true);

        return response()->json(['code' => 0, 'message' => '密码修改成功']);
    }

    /**
     * 4. 个人中心 - 上传头像
     * POST /api/user/avatar
     */
    public function uploadAvatar(\Illuminate\Http\Request $request)
    {
        /** @var \App\Models\User|null $user */

        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);
        $user = Auth::user();
        $oldAvatar = $user->avatar;

        $path = FileService::upload($request->file('avatar'), 'avatars');
        $user->avatar = $path;
        $user->save();

        // 删除旧头像
        if ($oldAvatar && !str_contains($oldAvatar, 'default')) {
            FileService::delete($oldAvatar);
        }

        AuditService::log($user->id, 'User', 'User', $user->id, 'upload_avatar', ['old' => $oldAvatar], ['new' => $path], true);

        return response()->json([
            'code' => 0,
            'message' => '头像上传成功',
            'data' => ['avatar' => asset('storage/' . $path)]
        ]);
    }

    /**
     * 5. 取消订单
     * PUT /api/orders/{id}/cancel
     */
    public function cancelOrder(CancelOrderRequest $request, $id)
    {
        /** @var \App\Models\User|null $user */

        $order = Order::with('product')->findOrFail($id);
        $user = Auth::user();

        // 权限检查：管理员或订单拥有者
        if (!$user->isAdmin() && $order->user_id != $user->id) {
            return response()->json(['code' => 403, 'message' => '无权操作该订单'], 403);
        }

        // 仅允许取消的状态: 已预订(10) 或 定制待审(20)
        if (!in_array($order->status, [Order::STATUS_BOOKED, Order::STATUS_DESIGN_PENDING])) {
            return response()->json(['code' => 1, 'message' => '当前订单状态不可取消'], 422);
        }

        DB::beginTransaction();
        try {
            // 释放预扣库存
            StockService::release(
                $order->product,
                $order->quantity,
                'order',
                $order->id,
                $user->id,
                '取消订单释放库存'
            );

            // 更新订单状态
            $order->status = Order::STATUS_CANCELLED;
            $order->cancel_reason = $request->cancel_reason ?? '用户取消';
            $order->cancelled_at = now();
            $order->cancelled_by = $user->id;
            $order->save();

            // 审计日志
            AuditService::log(
                $user->id,
                $user->isAdmin() ? 'Admin' : 'User',
                'Order',
                $order->id,
                'cancel_order',
                ['status' => $order->getOriginal('status')],
                ['status' => Order::STATUS_CANCELLED],
                true
            );

            DB::commit();

            return response()->json([
                'code' => 0,
                'message' => '订单已取消',
                'data' => ['order_id' => $order->id, 'status' => 'cancelled']
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['code' => 1, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 6. 商品图片 - 上传图片
     * POST /api/products/{productId}/images
     */
    public function uploadProductImage(UploadProductImageRequest $request, $productId)
    {
        /** @var \App\Models\User|null $user */

        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['code' => 403, 'message' => '只有管理员可以上传商品图片'], 403);
        }

        $product = Product::findOrFail($productId);

        $file = $request->file('image');
        $path = FileService::upload($file, 'products/' . $productId);

        // 获取图片尺寸（简单实现，需安装 intervention/image）
        list($width, $height) = getimagesize($file->getRealPath()) ?: [0, 0];

        $image = ProductImage::create([
            'product_id' => $product->id,
            'oss_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'width' => $width,
            'height' => $height,
            'is_main' => $request->boolean('is_main', false),
            'sort' => ProductImage::where('product_id', $product->id)->max('sort') + 1,
        ]);

        // 如果设置了主图，移除其他主图标记
        if ($image->is_main) {
            ProductImage::where('product_id', $product->id)->where('id', '!=', $image->id)->update(['is_main' => false]);
        }

        AuditService::log($user->id, 'Admin', 'ProductImage', $image->id, 'upload_image', null, $image->toArray(), true);

        return response()->json([
            'code' => 0,
            'message' => '上传成功',
            'data' => $image
        ]);
    }

    /**
     * 7. 商品图片 - 删除图片
     * DELETE /api/product-images/{id}
     */
    public function deleteProductImage($id)
    {
        /** @var \App\Models\User|null $user */

        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['code' => 403, 'message' => '权限不足'], 403);
        }

        $image = ProductImage::findOrFail($id);
        // 删除物理文件
        FileService::delete($image->oss_path);
        $image->delete();

        AuditService::log($user->id, 'Admin', 'ProductImage', $id, 'delete_image', $image->toArray(), null, true);

        return response()->json(['code' => 0, 'message' => '删除成功']);
    }

    /**
     * 8. 商品图片 - 设置主图
     * PUT /api/product-images/{id}/main
     */
    public function setMainImage($id)
    {
        /** @var \App\Models\User|null $user */

        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['code' => 403, 'message' => '权限不足'], 403);
        }

        $image = ProductImage::findOrFail($id);
        // 将该商品所有图片的主图标记取消
        ProductImage::where('product_id', $image->product_id)->update(['is_main' => false]);
        $image->is_main = true;
        $image->save();

        AuditService::log($user->id, 'Admin', 'ProductImage', $id, 'set_main_image', null, ['is_main' => true], true);

        return response()->json(['code' => 0, 'message' => '设置主图成功']);
    }

    /**
     * 9. 商品图片 - 调整排序
     * PUT /api/product-images/{id}/sort
     */
    public function sortProductImage(\Illuminate\Http\Request $request, $id)
    {
        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json(['code' => 403, 'message' => '权限不足'], 403);
        }
        $request->validate(['sort' => 'required|integer|min:0']);

        $image = ProductImage::findOrFail($id);
        $image->sort = $request->sort;
        $image->save();

        return response()->json(['code' => 0, 'message' => '排序更新成功']);
    }
}
