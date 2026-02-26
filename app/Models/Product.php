<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'category_id',
        'department',
        'name',
        'sku',
        'barcode',
        'type',
        'cost_price',
        'selling_price',
        'track_inventory',
        'stock',
        'stock_quantity',
        'alert_quantity',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'stock' => 'decimal:3',
        'track_inventory' => 'boolean',
        'is_active' => 'boolean',
        'stock_quantity' => 'integer',
        'alert_quantity' => 'integer',
    ];

    public function getMarginAttribute()
    {
        return $this->selling_price - $this->cost_price;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}