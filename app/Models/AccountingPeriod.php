<?php
// app/Models/AccountingPeriod.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToBusiness;

class AccountingPeriod extends Model
{
  use BelongsToBusiness;

  protected $fillable = [
    'name','start_date','end_date','status',
    'closed_at','closed_by','reopened_at','reopened_by','notes'
  ];

  protected $casts = [
    'start_date' => 'date',
    'end_date' => 'date',
    'closed_at' => 'datetime',
    'reopened_at' => 'datetime',
  ];
}
