<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ProformaItem extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'proforma_id','product_id','name','sku','description',
        'quantity','unit','unit_price',
        'discount_type','discount_value','discount_amount',
        'tax_rate','tax_amount',
        'line_subtotal','line_total','sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:3',
        'tax_amount' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function proforma()
    {
        return $this->belongsTo(Proforma::class);
    }
}
