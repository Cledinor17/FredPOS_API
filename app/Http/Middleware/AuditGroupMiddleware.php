<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class AuditGroupMiddleware
{
  public function handle($request, Closure $next)
  {
    $groupId = $request->header('X-Audit-Group') ?: (string) Str::uuid();
    app()->instance('auditGroupId', $groupId);

    $response = $next($request);
    $response->headers->set('X-Audit-Group', $groupId);

    return $response;
  }
}

