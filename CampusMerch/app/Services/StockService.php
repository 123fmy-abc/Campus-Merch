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

    /**
     * 最终确认扣减库存（审核通过时调用）
     * reserved_stock 释放，real_stock 减少，sold_count 增加
     * 使用乐观锁 version 字段防并发
     */
    public static function confirmDeduct(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        $maxRetries = 3;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $freshProduct = Product::find($product->id);
            if (!$freshProduct) {
                throw new \Exception('商品不存在');
            }

            $beforeRealStock    = $freshProduct->real_stock;
            $beforeReserved     = $freshProduct->reserved_stock;
            $oldVersion         = $freshProduct->version;

            // 校验库存充足
            if ($beforeRealStock < $quantity) {
                throw new \Exception('实际库存不足，无法完成扣减');
            }
            if ($beforeReserved < $quantity) {
                throw new \Exception('预扣库存数据异常');
            }

            // 乐观锁更新：同时减少 real_stock、reserved_stock、增加 sold_count
            $updated = Product::where('id', $freshProduct->id)
                ->where('version', $oldVersion)
                ->update([
                    'real_stock'     => $beforeRealStock - $quantity,
                    'reserved_stock' => $beforeReserved - $quantity,
                    'sold_count'     => $freshProduct->sold_count + $quantity,
                    'version'        => $oldVersion + 1,
                ]);

            if ($updated) {
                StockChangeLog::create([
                    'product_id'      => $freshProduct->id,
                    'type'            => StockChangeLog::TYPE_DEDUCT,   // 核销出库，统一使用模型常量
                    'change_qty'      => -$quantity,
                    'stock_before'    => $beforeRealStock,
                    'reserved_before' => $beforeReserved,
                    'stock_after'     => $beforeRealStock - $quantity,
                    'reserved_after'  => $beforeReserved - $quantity,
                    'related_type'    => $relatedType,
                    'related_id'      => $relatedId,
                    'operator_id'     => $operatorId,
                    'operator_type'   => $operatorId ? StockChangeLog::OPERATOR_ADMIN : null,
                    'remark'          => $remark ?: '审核通过，最终扣减库存',
                ]);
                return true;
            }

            if ($attempt == $maxRetries) {
                throw new \Exception('库存操作冲突，请重试');
            }
            usleep(50000); // 50ms 后重试
        }
        return false;
    }
}
