<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class DepartmentScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        // Si l'utilisateur est connecté et n'est pas "Admin Global"
        if (Auth::check() && Auth::user()->department !== 'global') {
            $builder->where('department', Auth::user()->department);
        }
    }
}
