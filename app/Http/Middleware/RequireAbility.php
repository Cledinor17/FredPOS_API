<?php
// app/Http/Middleware/RequireAbility.php
namespace App\Http\Middleware;

use Closure;

class RequireAbility
{
  public function handle($request, Closure $next, string $ability)
  {
    $user = $request->user();

    if (!$user || !$user->canAbility($ability)) {
      abort(403, "Missing ability: {$ability}");
    }

    return $next($request);
  }
}
