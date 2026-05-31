<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SeedMerchantRolesOnLogin;
use App\Support\MerchantTenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
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
        // A freshly-created merchant owner arrives from pos_admin with
        // an empty `merchant_super_admin` role; seed the catalogue +
        // force-sync the owner's permissions on login so they are never
        // locked out. See SeedMerchantRolesOnLogin for the full why.
        Event::listen(Login::class, SeedMerchantRolesOnLogin::class);
    }
}
