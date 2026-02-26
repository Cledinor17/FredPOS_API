<?php
namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
  public function log(
    string $action,
    ?Model $entity = null,
    ?array $before = null,
    ?array $after = null,
    array $metadata = []
  ): void {
    $business = app()->bound('currentBusiness') ? app('currentBusiness') : null;
    if (!$business) return;

    $req = request();
    AuditLog::create([
      'user_id' => auth()->id(),
      'group_id' => app()->bound('auditGroupId') ? app('auditGroupId') : null,
      'action' => $action,
      'entity_type' => $entity ? class_basename($entity) : null,
      'entity_id' => $entity?->id,
      'ip' => $req instanceof Request ? $req->ip() : null,
      'user_agent' => $req instanceof Request ? $req->userAgent() : null,
      'before' => $before,
      'after' => $after,
      'metadata' => $metadata ?: null,
      'occurred_at' => now(),
    ]);
  }

  public function snapshot(?Model $m): ?array
  {
    if (!$m) return null;
    // toArray inclut relations si load() ; on garde léger
    return $m->attributesToArray();
  }
}
