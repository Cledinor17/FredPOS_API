<?php
namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
  use BelongsToBusiness;

  protected $fillable = [
    'user_id','group_id','action','entity_type','entity_id',
    'ip','user_agent','before','after','metadata','occurred_at'
  ];

  protected $casts = [
    'before' => 'array',
    'after' => 'array',
    'metadata' => 'array',
    'occurred_at' => 'datetime',
  ];

  public function user()
  {
    return $this->belongsTo(User::class);
  }
}
