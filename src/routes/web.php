<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\CsrfTokenController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Portal\BranchesController;
use App\Http\Controllers\Portal\PortalUsersController;
use App\Http\Controllers\Pos\AddOnGroupsController;
use App\Http\Controllers\Pos\AddOnsController;
use App\Http\Controllers\Pos\BranchesController as PosBranchesController;
use App\Http\Controllers\Pos\BranchTransfersController;
use App\Http\Controllers\Pos\CategoriesController;
use App\Http\Controllers\Pos\CustomersController;
use App\Http\Controllers\Pos\DashboardController;
use App\Http\Controllers\Pos\DeliveryProvidersController;
use App\Http\Controllers\Pos\DeviceLiveController;
use App\Http\Controllers\Pos\DiscountsController;
use App\Http\Controllers\Pos\TaxesController;
use App\Http\Controllers\Pos\ExpenseCategoryController;
use App\Http\Controllers\Pos\ExpensesController;
use App\Http\Controllers\Pos\FloorsController;
use App\Http\Controllers\Pos\IngredientsController;
use App\Http\Controllers\Pos\IngredientStockController;
use App\Http\Controllers\Pos\IngredientUnitsController;
use App\Http\Controllers\Pos\LoyaltyController;
use App\Http\Controllers\Pos\PosStaffController;
use App\Http\Controllers\Pos\CompReasonsController;
use App\Http\Controllers\Pos\KitchenPositionsSettingController;
use App\Http\Controllers\Pos\PurchaseTaxRecoverableSettingController;
use App\Http\Controllers\Pos\ManagerApprovalSettingController;
use App\Http\Controllers\Pos\OrderCancellationSettingController;
use App\Http\Controllers\Pos\OrderNumberingSettingController;
use App\Http\Controllers\Pos\ReportsPositionsSettingController;
use App\Http\Controllers\Pos\VoidReasonsController;
use App\Http\Controllers\Pos\OffersController;
use App\Http\Controllers\Pos\OrdersController;
use App\Http\Controllers\Pos\PayoutsController;
use App\Http\Controllers\Pos\PhysicalItemsController;
use App\Http\Controllers\Pos\BranchTargetsController;
use App\Http\Controllers\Pos\DeliveriesController;
use App\Http\Controllers\Pos\PortalMessagesController;
use App\Http\Controllers\Pos\ProductionsController;
use App\Http\Controllers\Pos\ProductsController;
use App\Http\Controllers\Pos\ProductStockController;
use App\Http\Controllers\Pos\PurchaseReceiptController;
use App\Http\Controllers\Pos\ReportsController;
use App\Http\Controllers\Pos\RestockRequestsController;
use App\Http\Controllers\Pos\RolesController;
use App\Http\Controllers\Pos\SavedViewsController;
use App\Http\Controllers\Pos\ShiftsController;
use App\Http\Controllers\Pos\StaffMessagesController;
use App\Http\Controllers\Pos\StockController;
use App\Http\Controllers\Pos\StockCountsController;
use App\Http\Controllers\Pos\SuppliersController;
use App\Http\Controllers\Pos\TablesController;
use App\Http\Controllers\Pos\WasteController;
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

    // Phase D7 — forgot/reset password SPA pages. Declared here
    // (not via the authed SPA fallback) because the visitor is by
    // definition signed out when they click the emailed link; the
    // fallback route would bounce them to /login.
    Route::get('/forgot-password', SpaController::class)
        ->name('password.request');
    Route::get('/reset-password', SpaController::class)
        ->name('password.reset');

    // Phase D8 — the TOTP code page a 2FA-enrolled login bounces
    // to. Guest-only by definition: the pending challenge lives in
    // an UNauthenticated session; an authed visitor gets bounced
    // home by RedirectIfAuthenticated.
    Route::get('/two-factor-challenge', SpaController::class)
        ->name('two-factor.challenge');
});

// Phase D7 — public forgot/reset endpoints. forgot ALWAYS answers
// 200 (anti-enumeration); both are rate limited per (email, IP)
// inside the controller, mirroring the login throttle.
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware(RequireJsonRequest::class)
    ->name('auth.forgot-password');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware(RequireJsonRequest::class)
    ->name('auth.reset-password');

// POST /auth/login intentionally stays OUT of the guest guard so the
// controller can gracefully handle a request from a browser that
// still holds a valid session cookie (e.g. double-click, stale tab,
// or after the SPA's auth state was cleared but the server cookie
// wasn't). Rate limiting lives inside the controller +
// LoginRequest::ensureIsNotRateLimited.
Route::post('/auth/login', [AuthenticatedSessionController::class, 'store'])
    ->name('auth.login');

// Phase D8 — 2FA login challenge. Deliberately PUBLIC (the caller
// is by definition not yet authenticated); the endpoint is useless
// without the server-side pending state the login POST parked in
// the session, is throttled per (pending user, IP), and is the
// ONLY code path that converts that state into a real session.
Route::get('/auth/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
    ->middleware(RequireJsonRequest::class)
    ->name('auth.two-factor-challenge.show');
Route::post('/auth/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware(RequireJsonRequest::class)
    ->name('auth.two-factor-challenge');

// Authenticated SPA + JSON endpoints. EnsureMerchantSessionIsFresh
// enforces the sliding idle timeout on every request, so a tab
// left open for an hour bounces to /login on the next click
// instead of silently using a stale session.
Route::middleware([EnsureUserIsAuthenticated::class, EnsureMerchantSessionIsFresh::class])->group(function (): void {
    Route::get('/auth/user', [AuthenticatedSessionController::class, 'show'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.user');

    // Self-service password change (+ clears the must_change_password
    // flag the platform admin sets on a freshly-minted account).
    Route::post('/auth/change-password', [ChangePasswordController::class, 'update'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.change-password');

    // Phase D7 — self-service profile update (display name only;
    // email is the admin-managed login identifier).
    Route::patch('/auth/profile', [ProfileController::class, 'update'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.profile.update');

    // Phase D8 — self-service TOTP 2FA enrolment (per-user opt-in).
    // start → confirm-with-code → enabled (+ one-time recovery
    // codes); disable is a step-up (password + code/recovery code).
    Route::post('/auth/two-factor', [TwoFactorController::class, 'store'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.two-factor.store');
    Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.two-factor.confirm');
    Route::delete('/auth/two-factor', [TwoFactorController::class, 'destroy'])
        ->middleware(RequireJsonRequest::class)
        ->name('auth.two-factor.destroy');

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
        // Read-only: the admin-assigned devices on a branch (merchant
        // sees, can't control).
        Route::get('pos/branches/{branch:uuid}/devices', [PosBranchesController::class, 'devices'])
            ->name('pos.branches.devices');
        // Branch detail (v2 #11): per-branch products + staff + activity.
        Route::get('pos/branches/{branch:uuid}/products', [PosBranchesController::class, 'products'])
            ->name('pos.branches.products');
        Route::get('pos/branches/{branch:uuid}/staff', [PosBranchesController::class, 'staff'])
            ->name('pos.branches.staff');
        Route::get('pos/branches/{branch:uuid}/activity', [PosBranchesController::class, 'activity'])
            ->name('pos.branches.activity');
        Route::patch('pos/branches/{branch:uuid}', [PosBranchesController::class, 'update'])
            ->name('pos.branches.update');
        // Per-branch custom POS receipt template (header/CR/VAT/footer).
        Route::put('pos/branches/{branch:uuid}/receipt-template', [PosBranchesController::class, 'updateReceiptTemplate'])
            ->name('pos.branches.receipt-template');

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
        Route::post('products/import', [ProductsController::class, 'import'])
            ->name('products.import');
        // PD1 — the 3-step wizard's atomic create (product + add-on
        // groups + recipe + physical items + branches + provider
        // prices in ONE transaction; edit mode keeps the per-section
        // endpoints below).
        Route::post('products/wizard', [ProductsController::class, 'storeWizard'])
            ->name('products.store-wizard');
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
        // v2 #6 — product-unique add-ons: list + create groups privately owned
        // by this product (options managed via the addons endpoints above).
        Route::get('products/{product:uuid}/addon-groups', [ProductsController::class, 'addonGroups'])
            ->name('products.addon-groups.index');
        Route::post('products/{product:uuid}/addon-groups', [ProductsController::class, 'createAddonGroup'])
            ->name('products.addon-groups.store');

        Route::put('products/{product:uuid}/branches', [ProductsController::class, 'syncBranches'])
            ->name('products.sync-branches');

        // Phase 5b — product recipe replace. Idempotent — caller
        // PUTs the full desired list of recipe lines. Empty = no
        // recipe (pre-made goods, no inventory deduction on sale).
        // Snapshots the pre-edit state to pos_product_recipe_versions
        // on every change so historical COGS stays accurate.
        Route::put('products/{product:uuid}/recipe', [ProductsController::class, 'updateRecipe'])
            ->name('products.update-recipe');

        // P-G2 — physical-item components (cups/lids consumed per unit
        // sold). Same idempotent full-replace shape as the recipe;
        // component-options is the slim unit-product picker source.
        Route::get('products/component-options', [ProductsController::class, 'componentOptions'])
            ->name('products.component-options');
        // P-G3 — the product-as-add-on picker source (sellable products).
        Route::get('products/addon-link-options', [ProductsController::class, 'addonLinkOptions'])
            ->name('products.addon-link-options');
        Route::put('products/{product:uuid}/components', [ProductsController::class, 'updateComponents'])
            ->name('products.update-components');

        // PD1 — single-product read for the wizard's edit mode.
        // Declared AFTER the literal GET routes above (component-options
        // / addon-link-options) so the uuid binding can't swallow them.
        Route::get('products/{product:uuid}', [ProductsController::class, 'show'])
            ->name('products.show');

        // Phase 7 — UNIT (finished-good) product stock: central pool + allocate
        // to branches + branch->branch transfer + adjust + ledger.
        Route::get('products/{product:uuid}/stock', [ProductStockController::class, 'show'])
            ->name('product-stock.show');
        Route::post('products/{product:uuid}/stock/receive', [ProductStockController::class, 'receive'])
            ->name('product-stock.receive');
        Route::post('products/{product:uuid}/stock/receive-distribute', [ProductStockController::class, 'receiveDistribute'])
            ->name('product-stock.receive-distribute');
        Route::post('products/{product:uuid}/stock/allocate', [ProductStockController::class, 'allocate'])
            ->name('product-stock.allocate');
        Route::post('products/{product:uuid}/stock/transfer', [ProductStockController::class, 'transfer'])
            ->name('product-stock.transfer');
        Route::post('products/{product:uuid}/stock/adjust', [ProductStockController::class, 'adjust'])
            ->name('product-stock.adjust');
        // Record wastage of a cooked or bought-in product at a branch.
        Route::post('products/{product:uuid}/stock/waste', [ProductStockController::class, 'waste'])
            ->name('product-stock.waste');
        Route::get('products/{product:uuid}/stock/movements', [ProductStockController::class, 'movements'])
            ->name('product-stock.movements');

        // P-G1 — kitchen production history (read-only). Batches are
        // written exclusively by pos_api from the device Kitchen screen;
        // the portal audits who cooked what, std vs extra, and duration.
        // Gated on production.view inside the controller.
        Route::get('productions', [ProductionsController::class, 'index'])
            ->name('productions.index');
        // Graphical-view aggregates (totals + by-product/staff + daily trend +
        // status mix + recent-batch timeline) over the same filters.
        Route::get('productions/summary', [ProductionsController::class, 'summary'])
            ->name('productions.summary');

        // -------- P-G6 — Messaging --------
        // Channel 1: staff announcements to POS devices (messages.send-
        // gated; read receipts come back from pos_api). Channel 2: the
        // portal inbox — open to every signed-in user.
        Route::get('staff-messages', [StaffMessagesController::class, 'index'])
            ->name('staff-messages.index');
        Route::post('staff-messages', [StaffMessagesController::class, 'store'])
            ->name('staff-messages.store');
        Route::delete('staff-messages/{staffMessage:uuid}', [StaffMessagesController::class, 'destroy'])
            ->name('staff-messages.destroy');
        Route::get('messages/inbox', [PortalMessagesController::class, 'inbox'])
            ->name('messages.inbox');
        Route::get('messages/sent', [PortalMessagesController::class, 'sent'])
            ->name('messages.sent');
        Route::get('messages/unread-count', [PortalMessagesController::class, 'unreadCount'])
            ->name('messages.unread-count');
        Route::get('messages/recipients', [PortalMessagesController::class, 'recipients'])
            ->name('messages.recipients');
        Route::post('messages', [PortalMessagesController::class, 'store'])
            ->name('messages.store');
        Route::post('messages/{message:uuid}/read', [PortalMessagesController::class, 'read'])
            ->name('messages.read');

        // -------- P-G7 — Deliveries settlement --------
        // No-tender delivery-provider orders await the provider's
        // statement here. Confirm (matched) / adjust (variance recorded)
        // flips them to paid, dated at confirmation. deliveries.manage-
        // gated; F5 branch scope on lists + decisions.
        Route::get('deliveries', [DeliveriesController::class, 'index'])
            ->name('deliveries.index');
        Route::post('deliveries/confirm', [DeliveriesController::class, 'confirm'])
            ->name('deliveries.confirm');
        Route::post('deliveries/{order:uuid}/adjust', [DeliveriesController::class, 'adjust'])
            ->name('deliveries.adjust');

        // -------- P-G8 — Branch performance targets --------
        // Config (targets.manage) + the auth-only dashboard widget.
        // Windows finalize lazily on these GETs — no scheduler exists.
        Route::get('branch-targets', [BranchTargetsController::class, 'index'])
            ->name('branch-targets.index');
        Route::get('branch-targets/performance', [BranchTargetsController::class, 'performance'])
            ->name('branch-targets.performance');
        Route::post('branch-targets', [BranchTargetsController::class, 'store'])
            ->name('branch-targets.store');
        Route::patch('branch-targets/{target:uuid}', [BranchTargetsController::class, 'update'])
            ->name('branch-targets.update');
        Route::delete('branch-targets/{target:uuid}', [BranchTargetsController::class, 'destroy'])
            ->name('branch-targets.destroy');

        // -------- P-G9 — device Live (restricted scalefusion MDM) -----
        // Telemetry view + EXACTLY four safe commands; the sharp verbs
        // (lock / wipe / mark-lost / factory reset) have no route here
        // AND no client method — admin-only by construction. devices.
        // live.view gates the read, devices.live.control the commands;
        // tenant 404 before F5 scope 403 in the controller.
        Route::get('devices/{device:uuid}/live', [DeviceLiveController::class, 'show'])
            ->name('devices.live.show');
        Route::post('devices/{device:uuid}/live/reboot', [DeviceLiveController::class, 'reboot'])
            ->name('devices.live.reboot');
        Route::post('devices/{device:uuid}/live/shutdown', [DeviceLiveController::class, 'shutdown'])
            ->name('devices.live.shutdown');
        Route::post('devices/{device:uuid}/live/alarm', [DeviceLiveController::class, 'alarm'])
            ->name('devices.live.alarm');
        Route::post('devices/{device:uuid}/live/message', [DeviceLiveController::class, 'message'])
            ->name('devices.live.message');

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
        // Phase A — purchase batch history (Additions §2.4).
        Route::get('ingredients/{ingredient:uuid}/purchases', [IngredientsController::class, 'purchases'])
            ->name('ingredients.purchases');

        // P-G4 — central ingredient warehouse: company pool + Receive &
        // Distribute to branches + transfer + adjust + ledger (the ingredient
        // twin of the product-stock routes below).
        Route::get('ingredients/{ingredient:uuid}/stock', [IngredientStockController::class, 'show'])
            ->name('ingredient-stock.show');
        Route::post('ingredients/{ingredient:uuid}/stock/receive', [IngredientStockController::class, 'receive'])
            ->name('ingredient-stock.receive');
        Route::post('ingredients/{ingredient:uuid}/stock/receive-distribute', [IngredientStockController::class, 'receiveDistribute'])
            ->name('ingredient-stock.receive-distribute');
        Route::post('ingredients/{ingredient:uuid}/stock/allocate', [IngredientStockController::class, 'allocate'])
            ->name('ingredient-stock.allocate');
        Route::post('ingredients/{ingredient:uuid}/stock/transfer', [IngredientStockController::class, 'transfer'])
            ->name('ingredient-stock.transfer');
        Route::post('ingredients/{ingredient:uuid}/stock/adjust', [IngredientStockController::class, 'adjust'])
            ->name('ingredient-stock.adjust');
        Route::get('ingredients/{ingredient:uuid}/stock/movements', [IngredientStockController::class, 'movements'])
            ->name('ingredient-stock.movements');

        // v2 #13 — per-ingredient alternate units (base unit + factor). Read on
        // inventory.view, writes on inventory.manage (gated in the controller).
        Route::get('ingredients/{ingredient:uuid}/units', [IngredientUnitsController::class, 'index'])
            ->name('ingredients.units.index');
        Route::post('ingredients/{ingredient:uuid}/units', [IngredientUnitsController::class, 'store'])
            ->name('ingredients.units.store');
        // withoutScopedBindings: the param is `unit`, but the relation is
        // altUnits() (not units()), so Laravel's implicit nested scoping can't
        // resolve it — the controller does its own on-ingredient ownership check.
        Route::patch('ingredients/{ingredient:uuid}/units/{unit:uuid}', [IngredientUnitsController::class, 'update'])
            ->withoutScopedBindings()
            ->name('ingredients.units.update');
        Route::delete('ingredients/{ingredient:uuid}/units/{unit:uuid}', [IngredientUnitsController::class, 'destroy'])
            ->withoutScopedBindings()
            ->name('ingredients.units.destroy');

        // -------- PD3a — Physical items (things that cannot be eaten) --
        // Cups / boxes ('packaging', attachable to food) and bulbs /
        // cleaning items ('general', branch use). Created HERE, never in
        // the catalogue; stock rides ProductStockController (the rows are
        // internal unit products under the hood). inventory.view/manage.
        Route::get('physical-items', [PhysicalItemsController::class, 'index'])
            ->name('physical-items.index');
        Route::post('physical-items', [PhysicalItemsController::class, 'store'])
            ->name('physical-items.store');
        Route::patch('physical-items/{product:uuid}', [PhysicalItemsController::class, 'update'])
            ->name('physical-items.update');
        Route::delete('physical-items/{product:uuid}', [PhysicalItemsController::class, 'destroy'])
            ->name('physical-items.destroy');

        Route::get('suppliers', [SuppliersController::class, 'index'])
            ->name('suppliers.index');
        Route::post('suppliers', [SuppliersController::class, 'store'])
            ->name('suppliers.store');
        Route::patch('suppliers/{supplier:uuid}', [SuppliersController::class, 'update'])
            ->name('suppliers.update');
        Route::delete('suppliers/{supplier:uuid}', [SuppliersController::class, 'destroy'])
            ->name('suppliers.destroy');

        // -------- PD6 — Goods Received Note (Saved Purchase Receipt) --
        // One saved document for a whole delivery: many mixed lines
        // (ingredients + products + physical items) each with a cost +
        // optional inline branch split, plus named extra charges. Reading is
        // inventory.view; recording is inventory.manage + unrestricted scope
        // (it credits the central warehouse). Composes the per-item
        // receive/allocate/expense machinery in one atomic submit.
        Route::get('purchase-receipts', [PurchaseReceiptController::class, 'index'])
            ->name('purchase-receipts.index');
        Route::post('purchase-receipts', [PurchaseReceiptController::class, 'store'])
            ->name('purchase-receipts.store');
        Route::get('purchase-receipts/{receipt:uuid}', [PurchaseReceiptController::class, 'show'])
            ->name('purchase-receipts.show');
        // AP — record a (full or partial) payment against a credit receipt.
        Route::post('purchase-receipts/{receipt:uuid}/payments', [PurchaseReceiptController::class, 'recordPayment'])
            ->name('purchase-receipts.payments.store');

        // Per-branch stock: current balances, adjust, restock,
        // and paginated movement ledger.
        Route::get('branches/{branch:uuid}/stock', [StockController::class, 'index'])
            ->name('stock.index');
        Route::post('branches/{branch:uuid}/stock/adjust', [StockController::class, 'adjust'])
            ->name('stock.adjust');
        Route::post('branches/{branch:uuid}/stock/restock', [StockController::class, 'restock'])
            ->name('stock.restock');
        // Phase A — piece-aware purchase batch (Additions §2.4).
        Route::post('branches/{branch:uuid}/stock/purchase', [StockController::class, 'purchase'])
            ->name('stock.purchase');
        Route::get('branches/{branch:uuid}/stock-movements', [StockController::class, 'movements'])
            ->name('stock.movements');

        // Phase A — day-end stock counts + reconciliation (Additions §2.8).
        Route::get('branches/{branch:uuid}/stock-counts', [StockCountsController::class, 'index'])
            ->name('stock-counts.index');
        Route::post('branches/{branch:uuid}/stock-counts', [StockCountsController::class, 'store'])
            ->name('stock-counts.store');

        // -------- Branch stock transfers (§5.6) -----------
        // Immediate atomic move between two branches. List/show
        // gated on inventory.view; the transfer mutation on
        // inventory.manage. Source branch = the route branch.
        Route::get('branch-transfers', [BranchTransfersController::class, 'index'])
            ->name('branch-transfers.index');
        Route::get('branch-transfers/{transfer:uuid}', [BranchTransfersController::class, 'show'])
            ->name('branch-transfers.show');
        Route::post('branches/{branch:uuid}/transfers', [BranchTransfersController::class, 'store'])
            ->name('branch-transfers.store');

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
        Route::get('branches/{branch:uuid}/restock-suggestions', [RestockRequestsController::class, 'suggestions'])
            ->name('restock-requests.suggestions');
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
        // Phase D3 — distinct tag list for the filter dropdown.
        // MUST be declared before the {customer:uuid} wildcard or
        // the literal "tags" segment would bind as a uuid → 404.
        Route::get('customers/tags', [CustomersController::class, 'tags'])
            ->name('customers.tags');
        Route::get('customers/{customer:uuid}', [CustomersController::class, 'show'])
            ->name('customers.show');
        // Customer 360 (v2 #8): analytics rollups + paginated order history.
        Route::get('customers/{customer:uuid}/analytics', [CustomersController::class, 'analytics'])
            ->name('customers.analytics');
        Route::get('customers/{customer:uuid}/orders', [CustomersController::class, 'orders'])
            ->name('customers.orders');
        Route::post('customers', [CustomersController::class, 'store'])
            ->name('customers.store');
        Route::patch('customers/{customer:uuid}', [CustomersController::class, 'update'])
            ->name('customers.update');
        Route::delete('customers/{customer:uuid}', [CustomersController::class, 'destroy'])
            ->name('customers.destroy');
        Route::post('customers/{customer:uuid}/merge', [CustomersController::class, 'merge'])
            ->name('customers.merge');
        Route::post('customers/{customer:uuid}/plates', [CustomersController::class, 'attachPlate'])
            ->name('customers.plates.attach');
        Route::delete('customer-plates/{plate:uuid}', [CustomersController::class, 'detachPlate'])
            ->name('customers.plates.detach');

        // -------- Loyalty refactor — rules + accounts + wallet --
        // Multi-rule loyalty (blueprint §5.8): visit_based stamp
        // cards + spend_based points. Merchant-side gates:
        //   loyalty.view   GET endpoints
        //   loyalty.manage every write (rule CRUD + adjustments +
        //                   wallet top-up)
        //
        // The Phase 8 POS sale pipeline will earn/redeem via a
        // device-auth surface (EvaluateLoyalty + WriteLoyalty
        // TransactionAction), not these config routes.
        Route::get('loyalty/rules', [LoyaltyController::class, 'indexRules'])
            ->name('loyalty.rules.index');
        Route::post('loyalty/rules', [LoyaltyController::class, 'storeRule'])
            ->name('loyalty.rules.store');
        Route::patch('loyalty/rules/{rule:uuid}', [LoyaltyController::class, 'updateRule'])
            ->name('loyalty.rules.update');
        Route::delete('loyalty/rules/{rule:uuid}', [LoyaltyController::class, 'destroyRule'])
            ->name('loyalty.rules.destroy');
        Route::post('loyalty/rules/{rule:uuid}/pause', [LoyaltyController::class, 'pauseRule'])
            ->name('loyalty.rules.pause');
        Route::post('loyalty/rules/{rule:uuid}/resume', [LoyaltyController::class, 'resumeRule'])
            ->name('loyalty.rules.resume');

        Route::get('customers/{customer:uuid}/loyalty', [LoyaltyController::class, 'showCustomer'])
            ->name('loyalty.customer.show');
        Route::post('customers/{customer:uuid}/loyalty/adjust', [LoyaltyController::class, 'adjust'])
            ->name('loyalty.adjust');
        Route::get('customers/{customer:uuid}/loyalty/transactions', [LoyaltyController::class, 'transactions'])
            ->name('loyalty.transactions');
        // Wallet (store credit — separate from blueprint loyalty).
        Route::post('customers/{customer:uuid}/wallet/topup', [LoyaltyController::class, 'topUpWallet'])
            ->name('loyalty.wallet.topup');
        Route::post('customers/{customer:uuid}/wallet/adjust', [LoyaltyController::class, 'adjustWallet'])
            ->name('loyalty.wallet.adjust');
        Route::get('customers/{customer:uuid}/wallet/ledger', [LoyaltyController::class, 'walletLedger'])
            ->name('loyalty.wallet.ledger');

        // -------- Phase 6d — Discount rules (blueprint §5.9) --
        // Per-merchant rules. The Phase 6d-4 evaluator runs
        // at POS time (Phase 8+); these endpoints are config
        // management.
        Route::get('discounts', [DiscountsController::class, 'index'])
            ->name('discounts.index');
        Route::get('discounts/{discount:uuid}', [DiscountsController::class, 'show'])
            ->name('discounts.show');
        Route::post('discounts', [DiscountsController::class, 'store'])
            ->name('discounts.store');
        Route::patch('discounts/{discount:uuid}', [DiscountsController::class, 'update'])
            ->name('discounts.update');
        Route::delete('discounts/{discount:uuid}', [DiscountsController::class, 'destroy'])
            ->name('discounts.destroy');
        Route::post('discounts/{discount:uuid}/pause', [DiscountsController::class, 'pause'])
            ->name('discounts.pause');
        Route::post('discounts/{discount:uuid}/resume', [DiscountsController::class, 'resume'])
            ->name('discounts.resume');
        Route::put('discounts/{discount:uuid}/targets', [DiscountsController::class, 'syncTargets'])
            ->name('discounts.targets.sync');

        // -------- P-F9 — Offers / promotions --
        // type + type-specific config rules the POS device evaluates
        // (bogo / bundle / multi_buy / cheapest_free / spend_get).
        // Same permission keys as discounts (same risk class).
        Route::get('offers', [OffersController::class, 'index'])
            ->name('offers.index');
        Route::get('offers/{offer:uuid}', [OffersController::class, 'show'])
            ->name('offers.show');
        Route::post('offers', [OffersController::class, 'store'])
            ->name('offers.store');
        Route::patch('offers/{offer:uuid}', [OffersController::class, 'update'])
            ->name('offers.update');
        Route::delete('offers/{offer:uuid}', [OffersController::class, 'destroy'])
            ->name('offers.destroy');
        Route::post('offers/{offer:uuid}/pause', [OffersController::class, 'pause'])
            ->name('offers.pause');
        Route::post('offers/{offer:uuid}/resume', [OffersController::class, 'resume'])
            ->name('offers.resume');

        // -------- Phase 6 backfill — Expenses (blueprint §5.10) --
        // POS-captured expenses the merchant reviews. index gated
        // expenses.view; log/review/reject gated expenses.manage.
        // The POS sync feed (Phase 8) is the other writer.
        Route::get('expenses', [ExpensesController::class, 'index'])
            ->name('expenses.index');
        Route::post('expenses', [ExpensesController::class, 'store'])
            ->name('expenses.store');
        Route::post('expenses/{expense:uuid}/review', [ExpensesController::class, 'review'])
            ->name('expenses.review');
        Route::post('expenses/{expense:uuid}/reject', [ExpensesController::class, 'reject'])
            ->name('expenses.reject');

        // v2 #7 — custom expense categories (company-managed). index gated
        // expenses.view (auto-seeds the company's defaults); writes gated
        // expenses.manage. The POS /device/config bundle ships the active set.
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])
            ->name('expense-categories.index');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])
            ->name('expense-categories.store');
        Route::patch('expense-categories/{category:uuid}', [ExpenseCategoryController::class, 'update'])
            ->name('expense-categories.update');
        Route::delete('expense-categories/{category:uuid}', [ExpenseCategoryController::class, 'destroy'])
            ->name('expense-categories.destroy');

        // -------- Phase 7b-7 — Dashboard summary --
        // One GET that the landing page hits on mount. Gated
        // under reports.view (every dashboard widget is a
        // mini-report).
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])
            ->name('dashboard.summary');
        // Period-over-period sales comparison (dashboard + branch control center).
        Route::get('dashboard/sales-comparison', [DashboardController::class, 'salesComparison'])
            ->name('dashboard.sales-comparison');

        // -------- Saved views — per-user filter presets -----------
        // Personal bookmarks; NO permission gate (every authed user
        // manages their own). Ownership-scoped in the controller.
        Route::get('saved-views', [SavedViewsController::class, 'index'])
            ->name('saved-views.index');
        Route::post('saved-views', [SavedViewsController::class, 'store'])
            ->name('saved-views.store');
        Route::patch('saved-views/{savedView:uuid}', [SavedViewsController::class, 'update'])
            ->name('saved-views.update');
        Route::delete('saved-views/{savedView:uuid}', [SavedViewsController::class, 'destroy'])
            ->name('saved-views.destroy');

        // -------- Phase 7b — Reports + Audit Log (blueprint §13 Phase 7) --
        // Each report key dispatches to its own Action.
        // Adding a new report = adding an Action + a method on
        // ReportsController. Permission gate: reports.view.
        //
        // CSV export of any report key — gated on reports.export
        // (separate from reports.view). The {report}/export suffix
        // can't shadow the literal report routes below it.
        Route::get('reports/{report}/export', [ReportsController::class, 'export'])
            ->name('reports.export');
        Route::get('reports/sales', [ReportsController::class, 'sales'])
            ->name('reports.sales');
        Route::get('reports/customers', [ReportsController::class, 'customers'])
            ->name('reports.customers');
        Route::get('reports/discounts', [ReportsController::class, 'discounts'])
            ->name('reports.discounts');
        // Product-level view of every discount/offer/comp/gift — which exact item.
        Route::get('reports/discounted-comped-products', [ReportsController::class, 'discountedCompedProducts'])
            ->name('reports.discounted-comped-products');
        // Phase B — comp write-offs + per-shift cash variance.
        Route::get('reports/comps', [ReportsController::class, 'comps'])
            ->name('reports.comps');
        Route::get('reports/shifts', [ReportsController::class, 'shifts'])
            ->name('reports.shifts');
        // Phase B — manager re-open of a closed shift (same business day).
        Route::post('shifts/{shift:uuid}/reopen', [ShiftsController::class, 'reopen'])
            ->name('shifts.reopen');
        // v2 #17 — merchant payout / commission breakdown (gross, platform/bank/
        // other cuts, merchant net) over a date range.
        Route::get('reports/payouts', [ReportsController::class, 'payouts'])
            ->name('reports.payouts');
        // v2 #17 Phase B — the merchant's own payout history (read-only; the
        // platform creates + settles them). reports.view gated.
        Route::get('payouts', [PayoutsController::class, 'index'])
            ->name('payouts.index');
        Route::get('reports/product-performance', [ReportsController::class, 'productPerformance'])
            ->name('reports.product-performance');
        Route::get('reports/recipe-cost', [ReportsController::class, 'recipeCost'])
            ->name('reports.recipe-cost');
        Route::get('reports/staff-activity', [ReportsController::class, 'staffActivity'])
            ->name('reports.staff-activity');
        Route::get('reports/inventory-consumption', [ReportsController::class, 'inventoryConsumption'])
            ->name('reports.inventory-consumption');
        Route::get('reports/loss-waste', [ReportsController::class, 'lossWaste'])
            ->name('reports.loss-waste');
        Route::get('reports/restock-purchasing', [ReportsController::class, 'restockPurchasing'])
            ->name('reports.restock-purchasing');
        Route::get('reports/round-up-donation', [ReportsController::class, 'roundUpDonation'])
            ->name('reports.round-up-donation');
        // Audit log viewer (§5.12). Gated under audit_log.view
        // rather than reports.view -- see controller note.
        Route::get('reports/audit-log', [ReportsController::class, 'auditLog'])
            ->name('reports.audit-log');

        // Sales / Orders list (paginated, date-filterable). reports.view
        // gated; tenant-scoped to the company's own orders.
        Route::get('orders', [OrdersController::class, 'index'])
            ->name('orders.index');
        // Single-order detail (v2 #2). uuid-keyed, tenant-scoped.
        Route::get('orders/{order}', [OrdersController::class, 'show'])
            ->name('orders.show');

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

        // Company-level taxes (merchant settings). The Main POS fetches the
        // active set via /device/config and adds each, as its own line, on top
        // of the order total. Gated under CatalogueView / CatalogueManage --
        // a company-wide pricing setting, same risk class as product pricing.
        Route::get('taxes', [TaxesController::class, 'index'])->name('taxes.index');
        Route::post('taxes', [TaxesController::class, 'store'])->name('taxes.store');
        Route::patch('taxes/{tax:uuid}', [TaxesController::class, 'update'])->name('taxes.update');
        Route::delete('taxes/{tax:uuid}', [TaxesController::class, 'destroy'])->name('taxes.destroy');

        // PT — whether tracked purchase/input tax is recoverable (credited back
        // into net profit) or informational. Lives on the Taxes page; gated by
        // catalogue.view / catalogue.manage like the rest of the page.
        Route::get('settings/purchase-tax-recoverable', [PurchaseTaxRecoverableSettingController::class, 'show'])
            ->name('settings.purchase-tax-recoverable.show');
        Route::put('settings/purchase-tax-recoverable', [PurchaseTaxRecoverableSettingController::class, 'update'])
            ->name('settings.purchase-tax-recoverable.update');

        // v2 #14 — order cancellation policy: which staff positions may cancel a
        // completed order at the POS. Emitted in /device/config + enforced on the
        // device. Gated under orders.cancel (Manager + SuperAdmin).
        Route::get('settings/order-cancellation', [OrderCancellationSettingController::class, 'show'])
            ->name('settings.order-cancellation.show');
        Route::put('settings/order-cancellation', [OrderCancellationSettingController::class, 'update'])
            ->name('settings.order-cancellation.update');

        // P-F1 — manager approval policy: which staff positions may authorize
        // sensitive POS actions (comps, cancellations, gifts) by PIN — the
        // manager-fingerprint fallback. Emitted in /device/config + verified by
        // pos_api on /device/auth/verify-manager-pin. Same orders.cancel gate
        // as the cancellation policy it rides with.
        Route::get('settings/manager-approval', [ManagerApprovalSettingController::class, 'show'])
            ->name('settings.manager-approval.show');
        Route::put('settings/manager-approval', [ManagerApprovalSettingController::class, 'update'])
            ->name('settings.manager-approval.update');

        // P-F6 — device reports access policy: which staff positions may
        // open the Reports dashboard on the POS device. Emitted in
        // /device/config + enforced on the device. Same orders.cancel gate
        // as the two sibling position policies it rides with.
        Route::get('settings/reports-positions', [ReportsPositionsSettingController::class, 'show'])
            ->name('settings.reports-positions.show');
        Route::put('settings/reports-positions', [ReportsPositionsSettingController::class, 'update'])
            ->name('settings.reports-positions.update');

        // P-G1 — device Kitchen-section access policy: which staff positions
        // may open the Kitchen production screen and run cooked-product
        // batches. Emitted in /device/config + enforced on the device. Same
        // orders.cancel gate as the sibling position policies.
        Route::get('settings/kitchen-positions', [KitchenPositionsSettingController::class, 'show'])
            ->name('settings.kitchen-positions.show');
        Route::put('settings/kitchen-positions', [KitchenPositionsSettingController::class, 'update'])
            ->name('settings.kitchen-positions.update');

        // P-F8 — order numbering policy: how POS order numbers look
        // (prefix + zero-padded counter, e.g. KLD-0042), per-branch vs
        // company-wide sequence, optional daily reset. pos_api emits it
        // in /device/config and allocates the numbers server-side on
        // POST /device/orders/next-number. Same orders.cancel gate as
        // the sibling POS policy settings.
        Route::get('settings/order-numbering', [OrderNumberingSettingController::class, 'show'])
            ->name('settings.order-numbering.show');
        Route::put('settings/order-numbering', [OrderNumberingSettingController::class, 'update'])
            ->name('settings.order-numbering.update');

        // Phase B (Additions §1.2) — void + comp reason code lists. Same
        // orders.cancel gate as the cancellation policy they ride with;
        // both ship to the device in /device/config.
        Route::get('void-reasons', [VoidReasonsController::class, 'index'])
            ->name('void-reasons.index');
        Route::post('void-reasons', [VoidReasonsController::class, 'store'])
            ->name('void-reasons.store');
        Route::patch('void-reasons/{voidReason:uuid}', [VoidReasonsController::class, 'update'])
            ->name('void-reasons.update');
        Route::delete('void-reasons/{voidReason:uuid}', [VoidReasonsController::class, 'destroy'])
            ->name('void-reasons.destroy');
        Route::get('comp-reasons', [CompReasonsController::class, 'index'])
            ->name('comp-reasons.index');
        Route::post('comp-reasons', [CompReasonsController::class, 'store'])
            ->name('comp-reasons.store');
        Route::patch('comp-reasons/{compReason:uuid}', [CompReasonsController::class, 'update'])
            ->name('comp-reasons.update');
        Route::delete('comp-reasons/{compReason:uuid}', [CompReasonsController::class, 'destroy'])
            ->name('comp-reasons.destroy');

        // Per-product price overrides. PUT is upsert: creates
        // on first call, updates on subsequent. The two-uuid
        // URL captures both tenancy checks (product + provider
        // ownership) before the Action runs.
        //
        // withoutScopedBindings() opts out of Laravel's nested-
        // resource scoping: with two parameter bindings in one
        // route, Laravel by default tries to resolve the second
        // (provider) via $product->providers() — a relation we
        // deliberately don't define. The controller does its
        // own cross-tenant + cross-ownership checks against the
        // independently resolved models.
        Route::get('products/{product:uuid}/delivery-prices', [DeliveryProvidersController::class, 'listPrices'])
            ->name('products.delivery-prices.index');
        Route::put('products/{product:uuid}/delivery-prices/{provider:uuid}', [DeliveryProvidersController::class, 'setPrice'])
            ->withoutScopedBindings()
            ->name('products.delivery-prices.set');
        Route::delete('products/{product:uuid}/delivery-prices/{provider:uuid}', [DeliveryProvidersController::class, 'removePrice'])
            ->withoutScopedBindings()
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
