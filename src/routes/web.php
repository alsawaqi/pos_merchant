<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CsrfTokenController;
use App\Http\Controllers\Portal\BranchesController;
use App\Http\Controllers\Portal\PortalUsersController;
use App\Http\Controllers\Pos\BranchesController as PosBranchesController;
use App\Http\Controllers\Pos\FloorsController;
use App\Http\Controllers\Pos\PosStaffController;
use App\Http\Controllers\Pos\RolesController;
use App\Http\Controllers\Pos\TablesController;
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
        // Phase 4.8 — replace the role list. Permission-gated
        // on RolesManage inside the controller (not the same
        // gate as the portal-users.update PATCH which is
        // PortalUsersUpdate — a Manager can edit names but
        // shouldn't be able to grant SuperAdmin).
        Route::patch('portal-users/{portalUser}/roles', [PortalUsersController::class, 'assignRoles'])
            ->name('portal-users.assign-roles');

        // Read-only branches list for the Portal Users branch-
        // scope picker. Lean payload — no permission gate, every
        // authed merchant user needs this to render UI.
        Route::get('branches', [BranchesController::class, 'index'])
            ->name('branches.index');

        // -------- Phase 4.7 — Merchant Branches CRUD (no create/delete) ----
        // Different controller from the Portal one above — full
        // payload, permission-gated, supports show + update.
        // UUID-bound routes; refuseIfNotInTenant inside the
        // controller keeps cross-merchant lookups from leaking.
        Route::get('pos/branches', [PosBranchesController::class, 'index'])
            ->name('pos.branches.index');
        Route::get('pos/branches/{branch:uuid}', [PosBranchesController::class, 'show'])
            ->name('pos.branches.show');
        Route::patch('pos/branches/{branch:uuid}', [PosBranchesController::class, 'update'])
            ->name('pos.branches.update');

        // -------- Phase 4.8 — Roles & Permissions ----------
        // Role builder for merchant SuperAdmins. The catalog
        // endpoint returns the grouped permission tree used by
        // the editor checkbox grid; the CRUD endpoints manage
        // custom + system roles. System roles can be edited
        // (permissions) but not renamed or deleted.
        Route::get('roles/catalog', [RolesController::class, 'catalog'])
            ->name('roles.catalog');
        Route::get('roles', [RolesController::class, 'index'])
            ->name('roles.index');
        Route::post('roles', [RolesController::class, 'store'])
            ->name('roles.store');
        Route::patch('roles/{role}', [RolesController::class, 'update'])
            ->name('roles.update');
        Route::delete('roles/{role}', [RolesController::class, 'destroy'])
            ->name('roles.destroy');

        // -------- Phase 5 — Floor Plan (floors + tables) -----
        // Floors are branch-nested for the list + create; the
        // flat /floors/{floor:uuid} routes handle PATCH/DELETE
        // once the floor exists (no need to repeat the branch
        // uuid).
        Route::get('branches/{branch:uuid}/floors', [FloorsController::class, 'index'])
            ->name('floors.index');
        Route::post('branches/{branch:uuid}/floors', [FloorsController::class, 'store'])
            ->name('floors.store');
        Route::patch('floors/{floor:uuid}', [FloorsController::class, 'update'])
            ->name('floors.update');
        Route::delete('floors/{floor:uuid}', [FloorsController::class, 'destroy'])
            ->name('floors.destroy');

        // Tables live under a floor for creation, then become
        // first-class once they have a uuid.
        Route::post('floors/{floor:uuid}/tables', [TablesController::class, 'store'])
            ->name('tables.store');
        Route::patch('tables/{table:uuid}', [TablesController::class, 'update'])
            ->name('tables.update');
        Route::delete('tables/{table:uuid}', [TablesController::class, 'destroy'])
            ->name('tables.destroy');
        Route::post('tables/{table:uuid}/regenerate-qr', [TablesController::class, 'regenerateQr'])
            ->name('tables.regenerate-qr');

        // -------- Phase 4.6 — POS Staff (merchant's PIN-authed workforce) --
        // {posStaff} is bound by uuid (PosStaff::getRouteKeyName).
        // refuseIfNotInTenant inside the controller is what
        // actually keeps cross-merchant lookups from leaking
        // through.
        Route::get('pos-staff', [PosStaffController::class, 'index'])
            ->name('pos-staff.index');
        Route::post('pos-staff', [PosStaffController::class, 'store'])
            ->name('pos-staff.store');
        Route::patch('pos-staff/{posStaff}', [PosStaffController::class, 'update'])
            ->name('pos-staff.update');
        Route::post('pos-staff/{posStaff}/suspend', [PosStaffController::class, 'suspend'])
            ->name('pos-staff.suspend');
        Route::post('pos-staff/{posStaff}/reactivate', [PosStaffController::class, 'reactivate'])
            ->name('pos-staff.reactivate');
        Route::post('pos-staff/{posStaff}/terminate', [PosStaffController::class, 'terminate'])
            ->name('pos-staff.terminate');
        Route::post('pos-staff/{posStaff}/reset-pin', [PosStaffController::class, 'resetPin'])
            ->name('pos-staff.reset-pin');
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
