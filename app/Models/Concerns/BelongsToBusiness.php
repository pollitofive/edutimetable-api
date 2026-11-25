<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToBusiness
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToBusiness(): void
    {
        // Global scope that filters by the current business. It fails closed:
        // without a business in the context it returns nothing. Commands/jobs
        // that must see every business use withoutGlobalScope('business').
        static::addGlobalScope('business', function ($query) {
            // Use app() helper to resolve from service container
            // This ensures we get the singleton instance that was set in the middleware
            $currentBusiness = app(CurrentBusiness::class);

            if ($currentBusiness->hasId()) {
                $query->where($query->getModel()->getTable().'.business_id', $currentBusiness->id());
            } else {
                $query->whereRaw('1 = 0');
            }
        });

        // Automatically set business_id on create. An explicit business_id is
        // kept; without one and without a business context, creating fails.
        static::creating(function ($model) {
            if (! $model->business_id) {
                $currentBusiness = app(CurrentBusiness::class);

                if (! $currentBusiness->hasId()) {
                    throw new LogicException('Cannot create '.class_basename($model).' without a business context');
                }

                $model->business_id = $currentBusiness->id();
            }
        });

        // Prevent business_id changes on update
        static::updating(function ($model) {
            if ($model->isDirty('business_id') && $model->getOriginal('business_id') !== null) {
                $model->business_id = $model->getOriginal('business_id');
            }
        });
    }

    /**
     * Get the business that owns this model
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
