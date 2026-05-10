<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'order_no', 'user_id', 'product_id', 'quantity',
        'unit_price', 'total_amount','snapshot','size','color',
        'remark', 'recipient_name', 'recipient_phone', 'recipient_address',
        'status','submitted_at','design_uploaded_at','payment_proof_url','paid_at',
        'reviewed_by','reviewed_at','reject_reason','completed_at','cancel_reason',
        'cancelled_at','cancelled_by'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'snapshot' => 'array',
        'submitted_at' => 'datetime',
        'design_uploaded_at' => 'datetime',
        'paid_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function attachments()
    {
        return $this->hasMany(OrderAttachment::class);
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cancel($reason = null, $userId = null) {
        $this->status = 'cancelled';
        $this->cancel_reason = $reason;
        $this->cancelled_at = now();
        $this->cancelled_by = $userId;
        $this->save();

        // 释放预扣库存（reserved_stock）
        $this->product()->decrement('reserved_stock', $this->quantity);
    }
}
