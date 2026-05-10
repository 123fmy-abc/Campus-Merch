<?php
namespace App\Services;

use App\Models\Product;
use App\Models\StockChangeLog;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * 预扣库存（下单时）
     * 使用乐观锁 version 字段防并发
     */
    public static function reserve(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        // 重试最多 3 次（避免乐观锁冲突导致失败）
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // 重新查询最新数据（避免传入的 $product 过期）
            $freshProduct = Product::find($product->id);
            if (!$freshProduct) {
                throw new \Exception('商品不存在');
            }

            // 计算可用库存 = 实际库存 - 已预扣库存
            $available = $freshProduct->real_stock - $freshProduct->reserved_stock;
            if ($available < $quantity) {
                throw new \Exception('库存不足');
            }

            $beforeReserved = $freshProduct->reserved_stock;
            $newReserved = $beforeReserved + $quantity;
            $oldVersion = $freshProduct->version;

            // 使用乐观锁更新（仅当 version 未改变时才更新成功）
            $updated = Product::where('id', $freshProduct->id)
                ->where('version', $oldVersion)
                ->update([
                    'reserved_stock' => $newReserved,
                    'version' => $oldVersion + 1,
                ]);

            if ($updated) {
                // 更新成功，记录库存变更日志
                StockChangeLog::create([
                    'product_id' => $freshProduct->id,
                    'type' => StockChangeLog::TYPE_RESERVE,
                    'change_qty' => $quantity,
                    'stock_before' => $freshProduct->real_stock,   // 实际库存未变
                    'reserved_before' => $beforeReserved,
                    'stock_after' => $freshProduct->real_stock,
                    'reserved_after' => $newReserved,
                    'related_type' => $relatedType,
                    'related_id' => $relatedId,
                    'operator_id' => $operatorId,
                    'remark' => $remark,
                ]);
                return true;
            }

            // 更新失败（version 冲突），如果还有重试次数则继续循环，否则抛异常
            if ($attempt == $maxRetries) {
                throw new \Exception('库存操作冲突，请重试');
            }
            // 等待一小段时间后重试（可选，避免忙循环）
            usleep(50000); // 50ms
        }
        return false;
    }

    /**
     * 释放预扣库存（取消订单时）
     * 使用乐观锁 version 字段防并发
     */
    public static function release(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $freshProduct = Product::find($product->id);
            if (!$freshProduct) {
                throw new \Exception('商品不存在');
            }

            if ($freshProduct->reserved_stock < $quantity) {
                throw new \Exception('释放库存数量超过预扣库存');
            }

            $beforeReserved = $freshProduct->reserved_stock;
            $newReserved = $beforeReserved - $quantity;
            $oldVersion = $freshProduct->version;

            $updated = Product::where('id', $freshProduct->id)
                ->where('version', $oldVersion)
                ->update([
                    'reserved_stock' => $newReserved,
                    'version' => $oldVersion + 1,
                ]);

            if ($updated) {
                StockChangeLog::create([
                    'product_id' => $freshProduct->id,
                    'type' => StockChangeLog::TYPE_RELEASE,
                    'change_qty' => -$quantity,
                    'stock_before' => $freshProduct->real_stock,
                    'reserved_before' => $beforeReserved,
                    'stock_after' => $freshProduct->real_stock,
                    'reserved_after' => $newReserved,
                    'related_type' => $relatedType,
                    'related_id' => $relatedId,
                    'operator_id' => $operatorId,
                    'remark' => $remark,
                ]);
                return true;
            }

            if ($attempt == $maxRetries) {
                throw new \Exception('库存操作冲突，请重试');
            }
            usleep(50000);
        }
        return false;
    }
}
