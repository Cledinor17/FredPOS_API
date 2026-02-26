<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDocument extends Model
{
     use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'type','number','status','customer_id',
        'issue_date','expiry_date',
        'currency','exchange_rate',
        'reference','title','payment_terms_days',
        'salesperson_id','created_by',
        'billing_address','shipping_address',
        'shipping_method','shipping_cost',
        'discount_type','discount_value','discount_amount',
        'is_tax_inclusive',
        'subtotal','tax_total','total',
        'notes','terms','internal_notes',
        'sent_at','accepted_at',
        'converted_invoice_id','metadata',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'metadata' => 'array',
        'is_tax_inclusive' => 'boolean',
        'exchange_rate' => 'decimal:6',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(SalesDocumentItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function convertedInvoice()
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }
}
