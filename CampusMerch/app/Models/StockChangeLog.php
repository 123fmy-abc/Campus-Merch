<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockChangeLog extends Model
{
    /**
     * 库存变动类型常量
     */
    const TYPE_RESERVE   = 'reserve';   // 预扣（用户下单）
    const TYPE_DEDUCT    = 'deduct';    // 核销（订单完成）
    const TYPE_RELEASE   = 'release';   // 释放（订单取消/驳回）
    const TYPE_PURCHASE  = 'purchase';  // 采购入库
    const TYPE_ADJUST    = 'adjust';    // 手动调整
    const TYPE_RETURN    = 'return';    // 退货入库
    const TYPE_IN        = 'in';        // 其他入库
    const TYPE_OUT       = 'out';       // 其他出库

    /**
     * 操作人类型常量
     */
    const OPERATOR_USER   = 'User';     // 用户
    const OPERATOR_ADMIN  = 'Admin';    // 管理员
    const OPERATOR_SYSTEM = 'System';   // 系统

    /**
     * 可批量赋值的字段
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'type',
        'change_qty',
        'stock_before',
        'reserved_before',
        'stock_after',
        'reserved_after',
        'related_type',
        'related_id',
        'operator_id',
        'operator_type',
        'remark',
        'ip_address',
    ];

    /**
     * 字段类型转换
     *
     * @var array
     */
    protected $casts = [
        'change_qty'     => 'integer',
        'stock_before'   => 'integer',
        'reserved_before'=> 'integer',
        'stock_after'    => 'integer',
        'reserved_after' => 'integer',
        'operator_id'    => 'integer',
        'related_id'     => 'integer',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    /**
     * 关联商品
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 多态关联：操作人（可以是 User 或 Admin 模型）
     */
    public function operator()
    {
        // 注意：如果 operator_type 为 'User' 则关联 User 模型，为 'Admin' 可关联 User 模型（管理员也是用户）
        // 此处简化，直接关联 User 模型，确保 operator_id 存在对应 user
        if ($this->operator_type === self::OPERATOR_USER || $this->operator_type === self::OPERATOR_ADMIN) {
            return $this->belongsTo(User::class, 'operator_id');
        }
        return null;
    }

    /**
     * 多态关联：关联对象（Order, ImportLog 等）
     */
    public function related()
    {
        // 动态映射关系：可根据 related_type 返回对应模型实例
        $map = [
            'order'       => Order::class,
            //'import_log'  => ImportLog::class,
            'product'     => Product::class,
        ];
        if (isset($map[$this->related_type])) {
            return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
        }
        return null;
    }

    /**
     * 获取变动类型的中文描述（辅助方法）
     */
    public function getTypeTextAttribute()
    {
        $map = [
            self::TYPE_RESERVE   => '预扣库存',
            self::TYPE_DEDUCT    => '核销出库',
            self::TYPE_RELEASE   => '释放预扣',
            self::TYPE_PURCHASE  => '采购入库',
            self::TYPE_ADJUST    => '手动调整',
            self::TYPE_RETURN    => '退货入库',
            self::TYPE_IN        => '其他入库',
            self::TYPE_OUT       => '其他出库',
        ];
        return $map[$this->type] ?? $this->type;
    }
}
