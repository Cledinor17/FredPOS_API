<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Supplier extends Model
{
    protected $fillable = [
        'business_id',
        'department',
        'name',
        'contact_person',
        'phone',
        'address',
        'balance',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
