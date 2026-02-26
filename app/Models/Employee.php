<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;
    use BelongsToBusiness;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'job_title',
        'salary_amount',
        'salary_currency',
        'pay_frequency',
        'hired_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'salary_amount' => 'decimal:2',
        'hired_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EmployeePayment::class);
    }
}
