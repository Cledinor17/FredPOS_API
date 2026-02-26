<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
      use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'number','status','customer_id',
        'issue_date','due_date',
        'currency','exchange_rate',
        'reference','title','payment_terms_days',
        'salesperson_id','created_by',
        'billing_address','shipping_address',
        'shipping_method','shipping_cost',
        'discount_type','discount_value','discount_amount',
        'is_tax_inclusive',
        'subtotal','tax_total','total',
        'amount_paid','balance_due',
        'notes','terms','internal_notes',
        'source_document_id','source_document_type',
        'voided_at','voided_by','paid_at',
        'metadata',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'voided_at' => 'datetime',
        'paid_at' => 'datetime',
        'billing_address' => 'array',
        'shipping_address' => 'array',
        'metadata' => 'array',
        'exchange_rate' => 'decimal:6',
        'shipping_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'is_tax_inclusive' => 'boolean',
    ];

    public function items() { return $this->hasMany(InvoiceItem::class); }
    public function payments() { return $this->hasMany(InvoicePayment::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function voidedByUser() { return $this->belongsTo(User::class, 'voided_by'); }
}
