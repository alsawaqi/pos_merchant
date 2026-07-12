<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\PreventBackHistoryCache;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetMerchantTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind the production reverse proxy (TLS terminates there, the app's
        // nginx is only reachable on the internal charity_net), trust the
        // X-Forwarded-* headers so Laravel knows the request is HTTPS — else it
        // generates http:// URLs + non-secure cookies and logins/redirects break.
        $middleware->trustProxies(at: '*');

        // Append to EVERY response globally — covers SPA HTML, JSON
        // API, file downloads, the lot. SecurityHeaders sets CSP +
        // anti-clickjacking + COOP/CORP. PreventBackHistoryCache
        // sets no-store / Vary: Cookie so a back-button after
        // logout can never restore the merchant shell.
        $middleware->append(SecurityHeaders::class);
        $middleware->append(PreventBackHistoryCache::class);

        // Pin the merchant tenant scope + spatie team_id to the
        // signed-in user's company_id on every web request. Guest
        // requests short-circuit (no user → no scope change).
        // P-G5 — EnsureBranchScope runs AFTER the tenant pin (and after
        // SubstituteBindings, since appends land at the group's tail):
        // any route-bound model carrying a branch outside the user's
        // branch_scope_json aborts 403.
        $middleware->web(append: [
            SetMerchantTenantContext::class,
            EnsureBranchScope::class,
        ]);

        // Phase 2b — pin the tenant BEFORE route-model binding so the
        // BelongsToCompany global scope also constrains implicit {model:uuid}
        // resolution: a cross-tenant uuid now resolves to 404 through the ORM,
        // not only via the manual refuseIfNotInTenant() guard. This is safe
        // because SetMerchantTenantContext needs only $request->user() (resolved
        // once StartSession has run, which is earlier in the priority list), not
        // any bound model. EnsureBranchScope deliberately stays at the appended
        // tail — it inspects already-hydrated bound models, so it must run AFTER
        // SubstituteBindings.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetMerchantTenantContext::class,
        );

        // The merchant `web` guard redirects guests to /login —
        // matches the route name registered in routes/web.php.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
