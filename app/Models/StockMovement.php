<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'product_id',
        'direction',
        'reason',
        'quantity',
        'unit_cost',
        'source_type',
        'source_id',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];
}
