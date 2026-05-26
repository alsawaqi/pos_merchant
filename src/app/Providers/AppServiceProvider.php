<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\MerchantTenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance per request so the middleware writes the
        // tenant id once and every downstream resolution (actions,
        // resources, jobs) sees the same value.
        $this->app->singleton(MerchantTenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
