<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'code', 'description', 'specifications',
        'price', 'real_stock', 'reserved_stock', 'sold_count', 'cover_url',
        'custom_rule', 'need_design', 'status', 'version', 'max_buy_limit',
    ];

    protected $casts = [
        'specifications' => 'array',
        'price' => 'decimal:2',
        'need_design' => 'boolean',
        'custom_rule' => 'array',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function getAvailableStockAttribute()
    {
        return $this->real_stock - $this->reserved_stock;
    }

    // 兼容 API 文档的字段名
    public function getStockAttribute()
    {
        return $this->real_stock;
    }

    public function getReservedQtyAttribute()
    {
        return $this->reserved_stock;
    }

    public function getSoldQtyAttribute()
    {
        return $this->sold_count;
    }

    public function getCoverImageAttribute()
    {
        return $this->cover_url;
    }

    public function getAllowCustomAttribute()
    {
        return $this->need_design;
    }
}
