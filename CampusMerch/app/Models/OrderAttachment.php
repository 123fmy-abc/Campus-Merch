<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'type', 'file_name', 'file_path', 'file_url', 'mime_type', 'file_size', 'width', 'height'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeDesign($query)
    {
        return $query->where('type', 'design');
    }

    public function scopePaymentProof($query)
    {
        return $query->where('type', 'payment_proof');
    }
}
