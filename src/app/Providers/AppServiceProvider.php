<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\SeedMerchantRolesOnLogin;
use App\Support\MerchantTenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance per request so the middleware writes the
        // tenant id once and every downstream resolution (actions,
        // resources, jobs) sees the same value.
        //
        // scoped(), NOT singleton(): under php-fpm the two are equivalent
        // (fresh app per request), but under a long-lived worker runtime
        // (Octane/queue daemons) a singleton would leak one company's
        // tenant id into the next request — a cross-tenant data leak.
        // scoped instances are flushed between requests/jobs automatically.
        $this->app->scoped(MerchantTenantContext::class);
    }

    public function boot(): void
    {
        // A freshly-created merchant owner arrives from pos_admin with
        // an empty `merchant_super_admin` role; seed the catalogue +
        // force-sync the owner's permissions on login so they are never
        // locked out. See SeedMerchantRolesOnLogin for the full why.
        Event::listen(Login::class, SeedMerchantRolesOnLogin::class);

        $this->registerRecallerScopedGuard();
    }

    /**
     * Register the 'pos_merchant_session' guard driver — identical to the
     * framework's session driver ({@see \Illuminate\Auth\AuthManager::createSessionDriver})
     * except it uses a per-app remember-me cookie name. pos_merchant and pos_admin
     * share one APP_KEY and the pos_users table, so with the stock driver both apps
     * derive the SAME recaller cookie name and a merchant's "remember me" cookie
     * could auto-authenticate into pos_admin (bypassing the login-time user_type
     * gate) if SESSION_DOMAIN were ever widened past host-only. A distinct recaller
     * name closes that; the guard KEY stays 'web' so Spatie + Auth::guard('web')
     * are untouched.
     */
    private function registerRecallerScopedGuard(): void
    {
        Auth::extend('pos_merchant_session', function ($app, string $name, array $config) {
            $guard = new class(
                $name,
                Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                rehashOnLogin: (bool) $app['config']->get('hashing.rehash_on_login', true),
                timeboxDuration: (int) $app['config']->get('auth.timebox_duration', 200000),
                hashKey: (string) $app['config']->get('app.key'),
            ) extends SessionGuard {
                public function getRecallerName(): string
                {
                    return 'remember_pos_merchant_web';
                }
            };

            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            if (isset($config['remember'])) {
                $guard->setRememberDuration($config['remember']);
            }

            return $guard;
        });
    }
}
