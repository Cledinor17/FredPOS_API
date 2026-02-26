<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    use BelongsToBusiness;

    protected $fillable = [
        'code','name','email','phone',
        'billing_address','shipping_address',
        'tax_number','currency','payment_terms_days','credit_limit',
        'notes','is_active',
    ];

    protected $casts = [
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
    ];
}
