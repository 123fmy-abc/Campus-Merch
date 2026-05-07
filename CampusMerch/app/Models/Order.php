<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory,SoftDeletes;

    const STATUS_BOOKED = 10;           // 已预订
    const STATUS_DESIGN_PENDING = 20;   // 定制待审
    const STATUS_READY = 30;            // 待发货
    const STATUS_COMPLETED = 40;        // 已完成
    const STATUS_REJECTED = 50;         // 已驳回
    const STATUS_CANCELLED = 60;        // 已取消

    protected $fillable = [
        'order_no', 'user_id', 'product_id', 'quantity', 'spec', 'unit_price', 'total_amount', 'receiver_name',
        'receiver_phone', 'delivery_address', 'payment_proof_url', 'paid_at', 'remark', 'design_attachment_id',
        'tracking_no', 'tracking_company', 'shipped_at', 'reviewer_id', 'reviewed_at', 'reject_reason', 'cancel_reason',
        'cancelled_at', 'cancelled_by', 'source', 'ip_address', 'user_agent', 'booked_at', 'completed_at', 'status',
        'snapshot', 'size', 'color', 'submitted_at', 'design_uploaded_at', 'reviewed_by', 'review_remark', 'recipient_name',
        'recipient_phone', 'recipient_address'
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
}
