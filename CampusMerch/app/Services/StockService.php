<?php
namespace App\Services;

use App\Models\Product;
use App\Models\StockChangeLog;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * 预扣库存（下单时）
     */
    public static function reserve(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        return DB::transaction(function () use ($product, $quantity, $relatedType, $relatedId, $operatorId, $remark) {
            // 乐观锁检查 version，此处简化，实际可增加 version 比较
            if ($product->on_hand_stock - $product->reserved_qty < $quantity) {
                throw new \Exception('库存不足');
            }
            $beforeOnHand = $product->on_hand_stock;
            $beforeReserved = $product->reserved_qty;

            $product->reserved_qty += $quantity;
            $product->save();

            StockChangeLog::create([
                'product_id' => $product->id,
                'type' => StockChangeLog::TYPE_RESERVE,
                'change_qty' => $quantity,
                'stock_before' => $beforeOnHand,
                'reserved_before' => $beforeReserved,
                'stock_after' => $product->on_hand_stock,
                'reserved_after' => $product->reserved_qty,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'operator_id' => $operatorId,
                'remark' => $remark,
            ]);
            return true;
        });
    }

    /**
     * 释放预扣库存（取消订单时）
     */
    public static function release(Product $product, int $quantity, $relatedType, $relatedId, $operatorId = null, $remark = '')
    {
        return DB::transaction(function () use ($product, $quantity, $relatedType, $relatedId, $operatorId, $remark) {
            if ($product->reserved_qty < $quantity) {
                throw new \Exception('释放库存数量超过预扣库存');
            }
            $beforeOnHand = $product->on_hand_stock;
            $beforeReserved = $product->reserved_qty;

            $product->reserved_qty -= $quantity;
            $product->save();

            StockChangeLog::create([
                'product_id' => $product->id,
                'type' => StockChangeLog::TYPE_RELEASE,
                'change_qty' => -$quantity,
                'stock_before' => $beforeOnHand,
                'reserved_before' => $beforeReserved,
                'stock_after' => $product->on_hand_stock,
                'reserved_after' => $product->reserved_qty,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'operator_id' => $operatorId,
                'remark' => $remark,
            ]);
            return true;
        });
    }
}
