<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToBusiness;

class AccountMapping extends Model
{
  use BelongsToBusiness;

  protected $fillable = ['key','account_id'];

  public function account() { return $this->belongsTo(Account::class); }
}