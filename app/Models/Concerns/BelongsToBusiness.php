<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToBusiness
{
    protected static function bootBelongsToBusiness()
    {
        static::addGlobalScope('business', function (Builder $builder) {
            $business = app()->bound('currentBusiness') ? app('currentBusiness') : null;
            if ($business) {
                $builder->where($builder->getModel()->getTable().'.business_id', $business->id);
            }
        });

        static::creating(function ($model) {
            $business = app()->bound('currentBusiness') ? app('currentBusiness') : null;
            if ($business && empty($model->business_id)) {
                $model->business_id = $business->id;
            }
        });
    }
}
