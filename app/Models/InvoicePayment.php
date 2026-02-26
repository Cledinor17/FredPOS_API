<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
{
        use BelongsToBusiness;

  protected $fillable = [
  'invoice_id','kind','method','amount','currency','exchange_rate',
  'paid_at','reference','received_by','notes','metadata',
];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'metadata' => 'array',
    ];

    public function invoice() { return $this->belongsTo(Invoice::class); }
}
