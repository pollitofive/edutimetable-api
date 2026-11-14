<?php

namespace App\Providers;

use App\Services\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register CurrentBusiness as singleton
        $this->app->singleton(CurrentBusiness::class, function ($app) {
            return new CurrentBusiness;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Like exists:<table>,id but limited to the current business, so an id from another
        // business is rejected. Fails when there is no business context.
        // Usage: exists_in_business:courses
        Validator::extend('exists_in_business', function ($attribute, $value, $parameters) {
            $businessId = app(CurrentBusiness::class)->id();

            return $businessId !== null && DB::table($parameters[0])
                ->where('id', $value)
                ->where('business_id', $businessId)
                ->exists();
        }, 'The selected :attribute is invalid.');
    }
}
