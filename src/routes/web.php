<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CsrfTokenController;
use App\Http\Controllers\Portal\BranchesController;
use App\Http\Controllers\Portal\PortalUsersController;
use App\Http\Controllers\SpaController;
use App\Http\Middleware\EnsureMerchantSessionIsFresh;
use App\Http\Middleware\EnsureUserIsAuthenticated;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireJsonRequest;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routing strategy
|--------------------------------------------------------------------------
|
| Middleware on these routes is declared with FQCN classes rather than
| aliases so the guards cannot be silently disabled by a missing alias,
| stale config cache, or a future rename.
|
| Public:
|   GET  /auth/csrf     -> refresh CSRF token (XHR only)
|
| Guest-only:
|   GET  /login         -> SPA shell, redirects to / if already authed
|   POST /auth/login    -> issue session
|
| Authenticated:
|   GET  /{*}           -> SPA shell, redirects to /login if not authed
|   GET  /auth/user     -> JSON only, current user payload
|   POST /auth/logout   -> destroy session
*/

Route::get('/auth/csrf', CsrfTokenController::class)
    ->middleware(RequireJsonRequest::class)
    ->name('auth.csrf');

Route::middleware(RedirectIfAuthenticated::class)->group(function (): void {
    Route::get('/login', SpaController::class)
        ->name('login');
});

// POST /auth/login intentionally stays OUT of the guest guard so the
// controller can gracefully handle a request from a browser that
// still holds a valid session cookie (e.g. double-click, stale tab,
// or after the SPA's auth state was cleared but the server cookie
// wasn't). Rate limiting lives inside the controller +
// LoginRequest::ensureIsNotRateLimited.
Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->name('auth.login');

// Authenticated SPA + JSON endpoints. EnsureMerchantSessionIsFresh
// enforces the sliding idle timeout on every request, so a tab
// left open for an hour bounces to /login on the next click
// instead of silently using a stale session.
Route::middleware([EnsureUserIsAuthenticated::class, EnsureMerchantSessionIsFresh::class])->group(function (): void {
    Route::get('/auth/user', [AuthenticatedSessionController::class, 'show'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.user');

    // -------- Phase 4.5 — Portal Users (merchant manages own team) -----
    // All endpoints auto-scoped to the actor's company via the
    // MerchantTenantContext middleware that ran before this group.
    // Permission gating happens inside each controller method.
    Route::prefix('api')->middleware(RequireJsonRequest::class)->group(function (): void {
        Route::get('portal-users', [PortalUsersController::class, 'index'])
            ->name('portal-users.index');
        Route::post('portal-users', [PortalUsersController::class, 'store'])
            ->name('portal-users.store');
        Route::patch('portal-users/{portalUser}', [PortalUsersController::class, 'update'])
            ->name('portal-users.update');
        Route::post('portal-users/{portalUser}/suspend', [PortalUsersController::class, 'suspend'])
            ->name('portal-users.suspend');
        Route::post('portal-users/{portalUser}/reactivate', [PortalUsersController::class, 'reactivate'])
            ->name('portal-users.reactivate');
        Route::post('portal-users/{portalUser}/reset-password', [PortalUsersController::class, 'resetPassword'])
            ->name('portal-users.reset-password');

        // Read-only branches list for the scope multi-select.
        Route::get('branches', [BranchesController::class, 'index'])
            ->name('branches.index');
    });

    // SPA fallback — every authenticated path that isn't an API
    // endpoint or an auth route serves the shell. The Vue router
    // then takes over. The regex keeps /api/*, /auth/*, and the
    // /login route from accidentally serving HTML.
    Route::get('/{path?}', SpaController::class)
        ->where('path', '^(?!api(/|$)|auth(/|$)|login$).*')
        ->name('merchant.dashboard');
});

Route::post('/auth/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(Authenticate::class.':web')
    ->name('auth.logout');
