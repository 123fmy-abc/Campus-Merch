<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no', 'user_id', 'product_id', 'quantity', 'unit_price', 'total_amount', 'snapshot',
        'size', 'color', 'remark', 'status', 'submitted_at', 'design_uploaded_at',
        'payment_proof_url', 'paid_at', 'reviewed_at', 'reviewed_by', 'review_remark',
        'completed_at', 'recipient_name', 'recipient_phone', 'recipient_address',
        'cancel_reason', 'cancelled_at', 'cancelled_by'
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
