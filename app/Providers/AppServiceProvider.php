<?php

namespace App\Providers;

use App\Services\CurrentBusiness;
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
        //
    }
}
