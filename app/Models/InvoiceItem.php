<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
        use BelongsToBusiness;

    protected $fillable = [
        'invoice_id','product_id','name','sku','description',
        'quantity','unit','unit_price',
        'unit_cost','line_cost_total',
        'discount_type','discount_value','discount_amount',
        'tax_rate','tax_amount','line_subtotal','line_total','sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'line_total' => 'decimal:2',
        'line_cost_total' => 'decimal:2',
    ];
}
