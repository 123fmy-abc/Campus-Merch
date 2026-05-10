<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CgjController extends Controller
{
    /**
     * 0. 获取当前登录用户信息
     */
    public function getUser(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // 返回用户信息，排除敏感字段
        return response()->json([
            'code'    => 200,
            'message' => 'success',
            'data'    => [
                'id'               => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'avatar'           => $user->avatar ? Storage::url($user->avatar) : null,
                'phone'            => $user->phone,
                'default_address'  => $user->default_address,
                'role'             => $user->role,
                'email_verified_at' => $user->email_verified_at,
                'created_at'       => $user->created_at,
                'updated_at'       => $user->updated_at,
            ],
        ]);
    }

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
     * 2. 修改邮箱（仅需验证码 + JWT Token）
     */
    public function updateEmail(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'new_email'   => 'required|email|unique:users,email,' . auth()->id(),
            'code' => 'required|string|size:6',   // 必填 + 6位数字
        ], [
            'new_email.required' => '请输入新邮箱地址',
            'new_email.email'    => '邮箱格式不正确',
            'new_email.unique'   => '该邮箱已被占用，请换一个',
            'code.required'      => '请输入验证码',
            'code.string'        => '验证码必须是字符串',
            'code.size'          => '验证码必须为 6 位',
        ]);

        $user = auth()->user();

        // ✅ 启用验证码校验
        $cacheKey = "email_code:{$request->new_email}";  // 与 sendEmailCode 保持一致
        $cachedCode = Cache::get($cacheKey);

        if (!$cachedCode) {
            return response()->json([
                'code'    => 400,
                'message' => '验证码不存在或已过期，请重新发送',
            ], 400);
        }

        if ($cachedCode != $request->code) {
            return response()->json([
                'code'    => 400,
                'message' => '验证码错误',
            ], 400);
        }

        // 更新邮箱后删除验证码（防止重复使用）
        Cache::forget($cacheKey);

        $user->update([
            'email'             => $request->new_email,
            'email_verified_at' => now(),  // 自动标记已验证
        ]);

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
            'phone'           => ['sometimes', 'string', 'max:20', 'regex:/^1[3-9]\d{9}$/'],
            'default_address' => 'sometimes|string|max:500',
        ], [
            'phone.regex' => '手机号码格式不正确，请输入有效的中国大陆手机号',
        ]);

        $user = auth()->user();
        $fields = ['name', 'phone', 'default_address'];
        $changed = [];

        foreach ($fields as $field) {
            if ($request->has($field) && $request->input($field) !== $user->$field) {
                $changed[] = $field;
                $user->$field = $request->input($field);
            }
        }

        if (empty($changed)) {
            return response()->json([
                'code'    => 200,
                'message' => '无任何修改',
                'data'    => ['changed_fields' => []],
            ]);
        }

        $user->save();

        return response()->json([
            'code'    => 200,
            'message' => '资料更新成功',
            'data'    => [
                'user'           => $user->fresh(),
                'changed_fields' => $changed,
            ],
        ]);
    }

    /**
     * 4. 上传头像
     */
    public function uploadAvatar(Request $request)
    {
        /** @var \App\Models\User $user */
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png|max:10240',
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
     * 4.1 删除头像
     */
    public function deleteAvatar(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (!$user->avatar) {
            return response()->json([
                'code'    => 400,
                'message' => '当前没有设置头像',
            ], 400);
        }

        // 删除物理文件
        Storage::disk('public')->delete($user->avatar);

        // 清空数据库字段
        $user->avatar = null;
        $user->save();

        return response()->json([
            'code'    => 200,
            'message' => '头像已删除',
            'data'    => ['avatar_url' => null],
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
            'data'    => null
        ]);
    }

    /**
     * 6. 用户登出（JWT 登出）
     */
    public function logout()
    {
        /** @var \App\Models\User $user */
        try {
            $user = auth()->user();

            // 清除用户的 token 缓存
            Cache::forget('user_token:' . $user->id);

            // 使当前 token 失效
            auth()->logout();

            return response()->json([
                'code'    => 200,
                'message' => '退出登录成功',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '退出登录失败',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 7. 取消订单
     */
    public function cancelOrder(Request $request, $id)
    {
        // 使用 first() 代替 firstOrFail()
        $order = Order::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        // 手动判断订单是否存在
        if (!$order) {
            return response()->json([
                'code'    => 404,
                'message' => '订单不存在或不属于当前用户',
            ], 404);
        }

        // 以下是原有的状态校验逻辑
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

        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'code'    => 404,
                'message' => '商品不存在'
            ], 404);
        }

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

        // 同步封面 URL
        if ($isMain) {
            $product->cover_url = $url;
            $product->save();
        }

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

        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'code'    => 404,
                'message' => '商品不存在'
            ], 404);
        }

        $image = $product->images()->find($imageId);
        if (!$image) {
            return response()->json([
                'code'    => 404,
                'message' => '图片不存在'
            ], 404);
        }

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

        // 同步封面 URL
        if ($image->is_main) {
            $product->cover_url = $image->file_url;
            $product->save();
        } elseif (!$product->images()->where('is_main', true)->exists()) {
            // 所有图都不是主图了，清空 cover_url
            $product->cover_url = null;
            $product->save();
        }

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

        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'code'    => 404,
                'message' => '商品不存在'
            ], 404);
        }

        $image = $product->images()->find($imageId);
        if (!$image) {
            return response()->json([
                'code'    => 404,
                'message' => '图片不存在'
            ], 404);
        }

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

        // 同步封面 URL
        if ($wasMain) {
            $product->cover_url = $newMain ? $newMain->file_url : null;
            $product->save();
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
     * 从 product_images 表同步主图 URL 到 products.cover_url
     * 规则：取 is_main=1 的第一条；没有则清空
     */
    private function syncCoverUrl(Product $product): void
    {
        $mainImage = $product->images()->where('is_main', true)->first();
        $product->cover_url = $mainImage ? $mainImage->file_url : null;
        $product->save();
    }

    /**
     * 辅助方法：检查是否为管理员（role=2）
     */
    private function ensureAdmin()
    {
        $user = auth()->user() ?? abort(401, '请先登录');

        if ($user->role !== 'admin') {
            abort(403, '无权限访问');
        }
    }

}
