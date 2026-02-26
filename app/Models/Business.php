<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    protected $fillable = [
        'name','slug',
        'timezone','currency',
        'legal_name','email','phone','website','tax_number',
        'address','logo_path','invoice_footer',
    ];

    protected $casts = [
        'address' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // 🔥 Multi-users par business
    public function users()
    {
        return $this->belongsToMany(User::class, 'business_users')
            ->withPivot(['role','status','invited_at','joined_at','metadata'])
            ->withTimestamps();
    }

    public function customers() { return $this->hasMany(Customer::class); }
    public function employees() { return $this->hasMany(Employee::class); }
    public function employeePayments() { return $this->hasMany(EmployeePayment::class); }
    public function documents() { return $this->hasMany(SalesDocument::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function accounts() { return $this->hasMany(Account::class); }
    public function journalEntries() { return $this->hasMany(JournalEntry::class); }
    public function auditLogs() { return $this->hasMany(AuditLog::class); }
}
