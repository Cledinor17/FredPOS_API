<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToBusiness;

class JournalEntry extends Model
{
  use BelongsToBusiness;

  protected $fillable = [
    'entry_date','action','status','memo',
    'source_type','source_id',
    'currency','exchange_rate',
    'total_debit','total_credit',
    'posted_by','reverses_entry_id'
  ];

  protected $casts = [
    'entry_date' => 'date',
    'exchange_rate' => 'decimal:6',
    'total_debit' => 'decimal:2',
    'total_credit' => 'decimal:2',
  ];

  public function lines() { return $this->hasMany(JournalLine::class); }
}

class JournalLine extends Model
{
  use BelongsToBusiness;

  protected $fillable = [
    'journal_entry_id','account_id','line_no','description',
    'debit','credit','customer_id','vendor_id'
  ];

  protected $casts = [
    'debit' => 'decimal:2',
    'credit' => 'decimal:2',
  ];
}
