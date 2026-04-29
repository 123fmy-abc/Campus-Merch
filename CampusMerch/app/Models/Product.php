<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'code', 'description', 'specifications',
        'price', 'stock', 'reserved_qty', 'sold_qty', 'cover_image',
        'custom_rule', 'allow_custom', 'status', 'version'
    ];

    protected $casts = [
        'specifications' => 'array',
        'price' => 'decimal:2',
        'allow_custom' => 'boolean',
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

    public function getAvailableStockAttribute()
    {
        return $this->stock - $this->reserved_qty;
    }
}
