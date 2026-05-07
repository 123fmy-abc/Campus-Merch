<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CgjController extends Controller
{
    /**
     * 1. 修改密码
     */
    public function updatePassword(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|confirmed',
        ]);

        $user = auth()->user();  // JWT 获取当前用户
        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => '当前密码错误']);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'code'    => 200,
            'message' => '密码修改成功',
        ]);
    }

    /**
     * 2. 修改邮箱（需验证码，此处给予骨架）
     */
    public function updateEmail(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'new_email'   => 'required|email|unique:users,email,' . auth()->id(),
            'verify_code' => 'required|string',
            'password'    => 'required|string',
        ]);

        $user = auth()->user();
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['password' => '密码错误']);
        }

        // TODO: 验证码校验（从缓存中获取）
        // $cachedCode = Cache::get("email_verify_{$request->new_email}");
        // if (!$cachedCode || $cachedCode !== $request->verify_code) { ... }

        $user->update(['email' => $request->new_email]);

        return response()->json([
            'code'    => 200,
            'message' => '邮箱修改成功',
            'data'    => ['email' => $request->new_email],
        ]);
    }

    /**
     * 3. 修改个人资料
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'name'            => 'sometimes|string|max:255',
            'phone'           => 'sometimes|string|max:20',
            'default_address' => 'sometimes|string|max:500',
        ]);

        $user = auth()->user();
        $user->fill($request->only(['name', 'phone', 'default_address']));
        $user->save();

        return response()->json([
            'code'    => 200,
            'message' => '资料更新成功',
            'data'    => ['user' => $user->fresh()],
        ]);
    }

    /**
     * 4. 上传头像
     */
    public function uploadAvatar(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        $user = auth()->user();
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->avatar = $path;
        $user->save();

        return response()->json([
            'code'    => 200,
            'message' => '头像上传成功',
            'data'    => ['avatar_url' => Storage::url($user->avatar)],
        ]);
    }

    /**
     * 5. 注销账号（软删除）
     */
    public function destroyAccount(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'password' => 'required|string',
        ]);

        $user = auth()->user();
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['password' => '密码错误']);
        }

        // JWT 方式：可删除所有 token（需实现黑名单，简单处理则直接删用户）
        // 如果使用 tymon/jwt-auth，可以调用 invalidate 但需要 token 参数，此处简化
        $user->delete();

        return response()->json([
            'code'    => 200,
            'message' => '账号已注销',
        ]);
    }

    /**
     * 6. 用户登出（JWT 登出）
     */
    public function logout()
    {
        // tymon/jwt-auth 的登出方法会使当前 token 失效
        auth()->logout();  // 黑名单处理
        return response()->json([
            'code'    => 200,
            'message' => '登出成功',
        ]);
    }

    /**
     * 7. 取消订单
     */
    public function cancelOrder(Request $request, $id)
    {
        $order = Order::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $allowedStatuses = ['booked', 'design_pending'];
        if (!in_array($order->status, $allowedStatuses)) {
            return response()->json([
                'code'    => 400,
                'message' => '订单状态不允许取消',
                'errors'  => [
                    'status' => "当前订单状态为 {$order->status}，仅允许取消已预订或待审核的订单",
                ],
            ], 400);
        }

        $request->validate([
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($order, $request) {
            $order->cancel($request->cancel_reason, auth()->id());
        });

        return response()->json([
            'code'    => 200,
            'message' => '订单取消成功',
            'data'    => [
                'order_id'       => $order->id,
                'order_no'       => $order->order_no,
                'status'         => $order->status,
                'cancel_reason'  => $order->cancel_reason,
                'cancelled_at'   => $order->cancelled_at,
                'cancelled_by'   => auth()->id(),
                'released_stock' => $order->quantity,
            ],
        ]);
    }

    /**
     * 8. 上传商品图片（管理员）
     */
    public function uploadProductImage(Request $request, $productId)
    {
        $this->ensureAdmin();

        $product = Product::findOrFail($productId);

        $request->validate([
            'image'      => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
            'is_main'    => 'boolean',
            'sort_order' => 'integer|min:0|max:255',
        ]);

        $path = $request->file('image')->store("product-images/{$productId}", 'public');
        $url  = Storage::url($path);

        $isMain = $request->boolean('is_main', false);
        if ($isMain) {
            $product->images()->update(['is_main' => false]);
        }

        $image = $product->images()->create([
            'file_path'  => $path,
            'file_url'   => $url,
            'is_main'    => $isMain,
            'sort_order' => $request->integer('sort_order', 0),
        ]);

        return response()->json([
            'code'    => 200,
            'message' => '图片上传成功',
            'data'    => [
                'image_id'    => $image->id,
                'product_id'  => $product->id,
                'file_url'    => $image->file_url,
                'file_path'   => $image->file_path,
                'is_main'     => $image->is_main,
                'sort_order'  => $image->sort_order,
                'created_at'  => $image->created_at,
            ],
        ]);
    }

    /**
     * 9. 修改商品图片（管理员）
     */
    public function updateProductImage(Request $request, $productId, $imageId)
    {
        $this->ensureAdmin();

        $product = Product::findOrFail($productId);
        $image   = $product->images()->findOrFail($imageId);

        $request->validate([
            'image'      => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:5120',
            'is_main'    => 'boolean',
            'sort_order' => 'integer|min:0|max:255',
        ]);

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($image->file_path);
            $newPath = $request->file('image')->store("product-images/{$productId}", 'public');
            $image->file_path = $newPath;
            $image->file_url  = Storage::url($newPath);
            $image->save();
        }

        if ($request->boolean('is_main')) {
            $product->images()->where('id', '!=', $image->id)->update(['is_main' => false]);
        }

        $image->fill($request->only(['is_main', 'sort_order']))->save();

        return response()->json([
            'code'    => 200,
            'message' => '图片更新成功',
            'data'    => [
                'image_id'     => $image->id,
                'product_id'   => $product->id,
                'file_url'     => $image->file_url,
                'is_main'      => $image->is_main,
                'sort_order'   => $image->sort_order,
                'is_replaced'  => $request->hasFile('image'),
                'updated_at'   => $image->updated_at,
            ],
        ]);
    }

    /**
     * 10. 删除商品图片（管理员）
     */
    public function deleteProductImage(Request $request, $productId, $imageId)
    {
        $this->ensureAdmin();

        $product = Product::findOrFail($productId);
        $image   = $product->images()->findOrFail($imageId);

        $force = $request->boolean('force', false);
        if ($image->is_main && !$force) {
            return response()->json([
                'code'    => 400,
                'message' => '无法删除封面图',
                'errors'  => [
                    'is_main' => '该图片为封面图，如需删除请先设置其他图片为封面，或添加 force=1 参数强制删除',
                ],
            ], 400);
        }

        Storage::disk('public')->delete($image->file_path);
        $wasMain = $image->is_main;
        $image->delete();

        $newMainId = null;
        if ($wasMain && $force) {
            $newMain = $product->images()->orderBy('sort_order')->first();
            if ($newMain) {
                $newMain->is_main = true;
                $newMain->save();
                $newMainId = $newMain->id;
            }
        }

        return response()->json([
            'code'    => 200,
            'message' => '图片删除成功',
            'data'    => [
                'image_id'      => $imageId,
                'is_main'       => $wasMain,
                'new_main_id'   => $newMainId,
                'deleted_at'    => now()->toISOString(),
            ],
        ]);
    }
    /**
     * 辅助方法：检查是否为管理员（role=2）
     */
    private function ensureAdmin()
    {
        $user = auth()->user() ?? abort(401, '请先登录');

        if ($user->role !== 2) {
            abort(403, '无权限访问');
        }
    }

}
