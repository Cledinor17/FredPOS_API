<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToBusiness;

class Account extends Model
{
  use BelongsToBusiness;

  protected $fillable = [
    'parent_id','code','name','type','subtype',
    'normal_balance','is_system','is_active','description'
  ];
}
