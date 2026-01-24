<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Services\CurrentBusiness;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    /**
     * Boot the trait.
     */
    protected static function bootBelongsToBusiness(): void
    {
        // Add global scope to automatically filter by business_id
        static::addGlobalScope('business', function ($query) {
            // Use app() helper to resolve from service container
            // This ensures we get the singleton instance that was set in the middleware
            $currentBusiness = app(CurrentBusiness::class);

            if ($currentBusiness->hasId()) {
                $query->where($query->getModel()->getTable().'.business_id', $currentBusiness->id());
            }
        });

        // Automatically set business_id on create
        static::creating(function ($model) {
            if (! $model->business_id) {
                $currentBusiness = app(CurrentBusiness::class);

                if ($currentBusiness->hasId()) {
                    $model->business_id = $currentBusiness->id();
                }
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
