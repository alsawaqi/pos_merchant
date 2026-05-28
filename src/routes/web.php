<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CsrfTokenController;
use App\Http\Controllers\Portal\BranchesController;
use App\Http\Controllers\Portal\PortalUsersController;
use App\Http\Controllers\Pos\AddOnGroupsController;
use App\Http\Controllers\Pos\AddOnsController;
use App\Http\Controllers\Pos\BranchesController as PosBranchesController;
use App\Http\Controllers\Pos\CategoriesController;
use App\Http\Controllers\Pos\CustomersController;
use App\Http\Controllers\Pos\DeliveryProvidersController;
use App\Http\Controllers\Pos\FloorsController;
use App\Http\Controllers\Pos\IngredientsController;
use App\Http\Controllers\Pos\LoyaltyController;
use App\Http\Controllers\Pos\PosStaffController;
use App\Http\Controllers\Pos\ProductsController;
use App\Http\Controllers\Pos\RestockRequestsController;
use App\Http\Controllers\Pos\RolesController;
use App\Http\Controllers\Pos\StockController;
use App\Http\Controllers\Pos\SuppliersController;
use App\Http\Controllers\Pos\WasteController;
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
        // Phase 5.5 — bulk layout save from the visual planner.
        Route::post('floors/{floor:uuid}/layout', [FloorsController::class, 'saveLayout'])
            ->name('floors.save-layout');

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

        // -------- Phase 6 — Catalogue (Categories + Products) ----
        // Both gated by catalogue.{view,manage}. Categories
        // and products both bound by uuid.
        Route::get('categories', [CategoriesController::class, 'index'])
            ->name('categories.index');
        Route::post('categories', [CategoriesController::class, 'store'])
            ->name('categories.store');
        Route::patch('categories/{category:uuid}', [CategoriesController::class, 'update'])
            ->name('categories.update');
        Route::delete('categories/{category:uuid}', [CategoriesController::class, 'destroy'])
            ->name('categories.destroy');

        // Products list supports ?category={uuid} filter.
        Route::get('products', [ProductsController::class, 'index'])
            ->name('products.index');
        Route::post('products', [ProductsController::class, 'store'])
            ->name('products.store');
        Route::patch('products/{product:uuid}', [ProductsController::class, 'update'])
            ->name('products.update');
        Route::delete('products/{product:uuid}', [ProductsController::class, 'destroy'])
            ->name('products.destroy');

        // -------- Phase 4.9 — Modifiers / Add-on Groups ----
        // Add-on groups are catalog-tier config (a "Milk Choice"
        // group exists once per company, attaches to many
        // products via the pivot below or applies globally).
        // Read-gated on catalogue.view, mutations on
        // catalogue.manage (no separate modifier-permission per
        // the design note in MerchantPermission.php).
        Route::get('addon-groups', [AddOnGroupsController::class, 'index'])
            ->name('addon-groups.index');
        Route::post('addon-groups', [AddOnGroupsController::class, 'store'])
            ->name('addon-groups.store');
        Route::patch('addon-groups/{addonGroup:uuid}', [AddOnGroupsController::class, 'update'])
            ->name('addon-groups.update');
        Route::delete('addon-groups/{addonGroup:uuid}', [AddOnGroupsController::class, 'destroy'])
            ->name('addon-groups.destroy');

        // Add-ons (options inside a group). Create is nested under
        // the parent group; update + delete are flat-keyed.
        Route::post('addon-groups/{addonGroup:uuid}/addons', [AddOnsController::class, 'store'])
            ->name('addons.store');
        Route::patch('addons/{addon:uuid}', [AddOnsController::class, 'update'])
            ->name('addons.update');
        Route::delete('addons/{addon:uuid}', [AddOnsController::class, 'destroy'])
            ->name('addons.destroy');

        // Product ↔ add-on-group attachments. Idempotent sync —
        // caller PUTs the full desired list of group uuids.
        Route::put('products/{product:uuid}/addon-groups', [ProductsController::class, 'syncAddOnGroups'])
            ->name('products.sync-addon-groups');

        // Phase 5b — product recipe replace. Idempotent — caller
        // PUTs the full desired list of recipe lines. Empty = no
        // recipe (pre-made goods, no inventory deduction on sale).
        // Snapshots the pre-edit state to pos_product_recipe_versions
        // on every change so historical COGS stays accurate.
        Route::put('products/{product:uuid}/recipe', [ProductsController::class, 'updateRecipe'])
            ->name('products.update-recipe');

        // -------- Phase 5a — Inventory (Ingredients + Suppliers + Stock) --
        // All gated by inventory.{view,manage}. Branch-nested
        // stock endpoints take the branch by uuid + verify
        // tenant ownership; movement ledger is paginated.
        Route::get('ingredients', [IngredientsController::class, 'index'])
            ->name('ingredients.index');
        Route::post('ingredients', [IngredientsController::class, 'store'])
            ->name('ingredients.store');
        Route::patch('ingredients/{ingredient:uuid}', [IngredientsController::class, 'update'])
            ->name('ingredients.update');
        Route::delete('ingredients/{ingredient:uuid}', [IngredientsController::class, 'destroy'])
            ->name('ingredients.destroy');

        Route::get('suppliers', [SuppliersController::class, 'index'])
            ->name('suppliers.index');
        Route::post('suppliers', [SuppliersController::class, 'store'])
            ->name('suppliers.store');
        Route::patch('suppliers/{supplier:uuid}', [SuppliersController::class, 'update'])
            ->name('suppliers.update');
        Route::delete('suppliers/{supplier:uuid}', [SuppliersController::class, 'destroy'])
            ->name('suppliers.destroy');

        // Per-branch stock: current balances, adjust, restock,
        // and paginated movement ledger.
        Route::get('branches/{branch:uuid}/stock', [StockController::class, 'index'])
            ->name('stock.index');
        Route::post('branches/{branch:uuid}/stock/adjust', [StockController::class, 'adjust'])
            ->name('stock.adjust');
        Route::post('branches/{branch:uuid}/stock/restock', [StockController::class, 'restock'])
            ->name('stock.restock');
        Route::get('branches/{branch:uuid}/stock-movements', [StockController::class, 'movements'])
            ->name('stock.movements');

        // -------- Phase 5c — Waste records --
        // Branch-scoped. List paginated + filterable by
        // ingredient / reason / occurred_at range. Store writes
        // the WasteRecord + signed-negative stock_movement
        // atomically (via WriteStockMovementAction inside
        // RecordWasteAction).
        Route::get('branches/{branch:uuid}/waste', [WasteController::class, 'index'])
            ->name('waste.index');
        Route::post('branches/{branch:uuid}/waste', [WasteController::class, 'store'])
            ->name('waste.store');

        // -------- Phase 5c — Restock request workflow --
        // Most routes are NOT branch-nested because HQ reviewers
        // see + act on requests from every branch through a
        // single inbox. Creation IS branch-nested because the
        // requesting branch is the meaningful parent. UUID-bound
        // for stable URLs across status transitions.
        //
        // create + update + submit + cancel gate on
        // RestockRequestCreate (the requester side).
        // approve + reject + allocate gate on
        // RestockRequestReview (the HQ side).
        Route::get('restock-requests', [RestockRequestsController::class, 'index'])
            ->name('restock-requests.index');
        Route::get('restock-requests/{restockRequest:uuid}', [RestockRequestsController::class, 'show'])
            ->name('restock-requests.show');
        Route::post('branches/{branch:uuid}/restock-requests', [RestockRequestsController::class, 'store'])
            ->name('restock-requests.store');
        Route::patch('restock-requests/{restockRequest:uuid}', [RestockRequestsController::class, 'update'])
            ->name('restock-requests.update');
        Route::post('restock-requests/{restockRequest:uuid}/submit', [RestockRequestsController::class, 'submit'])
            ->name('restock-requests.submit');
        Route::post('restock-requests/{restockRequest:uuid}/approve', [RestockRequestsController::class, 'approve'])
            ->name('restock-requests.approve');
        Route::post('restock-requests/{restockRequest:uuid}/reject', [RestockRequestsController::class, 'reject'])
            ->name('restock-requests.reject');
        Route::post('restock-requests/{restockRequest:uuid}/cancel', [RestockRequestsController::class, 'cancel'])
            ->name('restock-requests.cancel');
        Route::post('restock-requests/{restockRequest:uuid}/allocate', [RestockRequestsController::class, 'allocate'])
            ->name('restock-requests.allocate');

        // -------- Phase 6a — Customers + vehicle plates --
        // Customer book lookup endpoints. UUID-bound for stable
        // URLs across name/phone edits. The list endpoint takes
        // an optional ?search=X query that does a LIKE across
        // name + phone + plates (the canonical/uppercase form
        // for plates) so the merchant can find a customer by
        // any of the three.
        //
        // Plate routes:
        //   - attach is nested under the customer (we know which
        //     customer to attach to via the URL)
        //   - detach is flat ({plate:uuid} resolves directly)
        //     because plates only know their parent customer
        //     after we resolve the row, and the controller
        //     re-checks tenancy before any work
        Route::get('customers', [CustomersController::class, 'index'])
            ->name('customers.index');
        Route::get('customers/{customer:uuid}', [CustomersController::class, 'show'])
            ->name('customers.show');
        Route::post('customers', [CustomersController::class, 'store'])
            ->name('customers.store');
        Route::patch('customers/{customer:uuid}', [CustomersController::class, 'update'])
            ->name('customers.update');
        Route::delete('customers/{customer:uuid}', [CustomersController::class, 'destroy'])
            ->name('customers.destroy');
        Route::post('customers/{customer:uuid}/plates', [CustomersController::class, 'attachPlate'])
            ->name('customers.plates.attach');
        Route::delete('customer-plates/{plate:uuid}', [CustomersController::class, 'detachPlate'])
            ->name('customers.plates.detach');

        // -------- Phase 6b — Loyalty + wallet --
        // The merchant-side gates:
        //   loyalty.view   GET endpoints
        //   loyalty.manage every write (config edit + balance
        //                   adjust + wallet top-up)
        //
        // The Phase 7+ POS terminal will write to the ledgers
        // via a different surface (device-auth + the sale
        // pipeline), not these routes.
        Route::get('loyalty/config', [LoyaltyController::class, 'showConfig'])
            ->name('loyalty.config.show');
        Route::patch('loyalty/config', [LoyaltyController::class, 'upsertConfig'])
            ->name('loyalty.config.upsert');

        Route::get('customers/{customer:uuid}/loyalty', [LoyaltyController::class, 'showCustomer'])
            ->name('loyalty.customer.show');
        Route::post('customers/{customer:uuid}/points/adjust', [LoyaltyController::class, 'adjustPoints'])
            ->name('loyalty.points.adjust');
        Route::post('customers/{customer:uuid}/wallet/topup', [LoyaltyController::class, 'topUpWallet'])
            ->name('loyalty.wallet.topup');
        Route::post('customers/{customer:uuid}/wallet/adjust', [LoyaltyController::class, 'adjustWallet'])
            ->name('loyalty.wallet.adjust');
        Route::get('customers/{customer:uuid}/points/ledger', [LoyaltyController::class, 'pointLedger'])
            ->name('loyalty.points.ledger');
        Route::get('customers/{customer:uuid}/wallet/ledger', [LoyaltyController::class, 'walletLedger'])
            ->name('loyalty.wallet.ledger');

        // -------- Phase 6c — Delivery providers + per-product prices --
        // Per-merchant 3rd-party delivery aggregators (Talabat,
        // Otlob, etc.) with per-product price overrides.
        // Gated under the existing CatalogueView / CatalogueManage
        // -- setting provider prices IS product pricing.
        //
        // Price-resolution chain at POS time (Phase 8+):
        //   override -> products.delivery_price -> products.base_price
        Route::get('delivery-providers', [DeliveryProvidersController::class, 'index'])
            ->name('delivery-providers.index');
        Route::post('delivery-providers', [DeliveryProvidersController::class, 'store'])
            ->name('delivery-providers.store');
        Route::patch('delivery-providers/{provider:uuid}', [DeliveryProvidersController::class, 'update'])
            ->name('delivery-providers.update');
        Route::delete('delivery-providers/{provider:uuid}', [DeliveryProvidersController::class, 'destroy'])
            ->name('delivery-providers.destroy');

        // Per-product price overrides. PUT is upsert: creates
        // on first call, updates on subsequent. The two-uuid
        // URL captures both tenancy checks (product + provider
        // ownership) before the Action runs.
        Route::get('products/{product:uuid}/delivery-prices', [DeliveryProvidersController::class, 'listPrices'])
            ->name('products.delivery-prices.index');
        Route::put('products/{product:uuid}/delivery-prices/{provider:uuid}', [DeliveryProvidersController::class, 'setPrice'])
            ->name('products.delivery-prices.set');
        Route::delete('products/{product:uuid}/delivery-prices/{provider:uuid}', [DeliveryProvidersController::class, 'removePrice'])
            ->name('products.delivery-prices.remove');

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
