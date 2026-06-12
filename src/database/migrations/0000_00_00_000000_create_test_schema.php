<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated test-only schema for pos_merchant.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Production schema is owned exclusively by pos_admin's
 * `database/migrations/` — both apps share `charity_db` on the
 * deployed Postgres and pos_merchant intentionally has no
 * migrations of its own there (running it would risk drifting the
 * shared schema). But the test suite uses RefreshDatabase against
 * an in-memory sqlite, which needs SOMETHING to migrate.
 *
 * Rather than symlink or duplicate the dozen+ pos_admin migrations
 * (and their later ALTER TABLEs), this single file is the
 * "test fixture" of the schema — only the columns the test suite
 * actually reads, only the tables the test suite actually touches,
 * shaped as the FINAL state after every prod migration has run.
 *
 * The filename starts with `0000_00_00_000000` so it sorts BEFORE
 * any future migrations and so prod deployments (which run
 * `migrate` against Postgres) skip it via the IF NOT EXISTS
 * semantics in `Schema::create`. This file should never run
 * against the prod DB — but the structural guard is still nice.
 *
 * WHEN TO EDIT
 * ------------
 * Add a column / table here when:
 *   - pos_admin lands a new prod migration AND
 *   - a pos_merchant test reads or writes that column/table.
 *
 * If pos_admin adds a column you don't need in tests, leave this
 * file alone — divergence is fine for unread columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Production safety: pos_merchant owns NO schema on the shared charity_db
        // (pos_admin owns it). This file is a TEST fixture only — guard it so an
        // accidental `php artisan migrate` against a real DB is a harmless no-op.
        if (! app()->environment('testing')) {
            return;
        }

        // ---- pos_companies ----------------------------------------
        Schema::create('pos_companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('commercial_registration_number')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status')->default('onboarding');
            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            // Phase 6 — company-level default VAT rate (5.00 = 5%).
            // pos_products.tax_rate overrides when set.
            $table->decimal('default_tax_rate', 5, 2)->default(5.00);
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- pos_users ----------------------------------------------
        // pos_users carries BOTH populations (platform_admin and
        // merchant) and the portal-user extension columns. Phone is
        // TEXT to absorb the encrypted ciphertext (~3x plaintext).
        Schema::create('pos_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('pos_companies')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->text('phone')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            // Nullable to match the post-2026_05_24_050000 prod
            // state (portal-invite flow could create a row with no
            // password until setup). The create-with-password flow
            // populates it on insert.
            $table->string('password')->nullable();
            $table->boolean('must_change_password')->default(false);
            // Phase D8 — opt-in TOTP 2FA (mirrors pos_admin's
            // 2026_07_06_010000 migration): encrypted secret,
            // encrypted JSON of sha256-hashed recovery codes,
            // confirmed_at NULL until the first valid code.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('setup_token_hash', 64)->nullable()->unique();
            $table->timestamp('setup_token_expires_at')->nullable();
            $table->string('user_type')->default('merchant');
            $table->json('branch_scope_json')->nullable();
            $table->timestamp('invited_at')->nullable();
            // Self-FK — invited_by_admin_id references pos_users(id).
            // Created here without the FK constraint to avoid sqlite's
            // restriction on forward-references during create; we add
            // the FK in a follow-up Schema::table call below.
            $table->unsignedBigInteger('invited_by_admin_id')->nullable();
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->string('timezone')->default('Asia/Muscat');
            $table->string('locale', 10)->default('en');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // ---- pos_branches -----------------------------------------
        Schema::create('pos_branches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('code')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->json('receipt_template')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'pos_branches_company_code_unique');
        });

        // ---- pos_audit_logs ---------------------------------------
        // Both apps write here. The (nullable) actor + company + branch
        // foreign keys exist so tests that assert event provenance can
        // assertDatabaseHas without a fancy join.
        Schema::create('pos_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('pos_companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->nullOnDelete();
            $table->string('event')->index();
            $table->nullableMorphs('auditable');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- pos_password_reset_tokens (Phase D7) -------------------
        // Self-service forgot-password tokens. user_id-keyed (NOT the
        // Laravel broker's email-keyed default — that table belongs to
        // the charity app on the live shared DB). token_hash stores
        // SHA-256 of the raw token; used_at makes tokens single-use.
        // Owned by pos_admin's 2026_07_05_010000 migration in prod.
        Schema::create('pos_password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('pos_users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- pos_staff (Phase 4.6) --------------------------------
        Schema::create('pos_staff', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('name');
            $table->text('phone')->nullable();
            $table->string('staff_code', 64)->nullable();
            $table->string('pin_hash');
            $table->string('position', 32);
            $table->string('status', 32)->default('active');
            $table->date('hired_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Sqlite's UNIQUE behaviour treats multiple NULLs as
            // distinct, which is exactly what the partial-unique
            // index does on Postgres — so a plain unique here is
            // a faithful test mirror. Re-hires can reuse codes via
            // the soft-delete + (NULL vs NULL) trick.
            $table->unique(['company_id', 'staff_code'], 'pos_staff_company_code_unique');
        });

        // ---- pos_product_categories (Phase 6a) --------------------
        Schema::create('pos_product_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            // Subcategories: NULL = top-level, set = subcategory (2-level cap
            // enforced in the action/request layer). Plain foreign id, no
            // DB-level self-FK — matches the real ALTER migration.
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            // Phase D2 — §5.5.1 branch availability: NULL = all branches,
            // else a JSON array of pos_branches ids.
            $table->json('branch_availability_json')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_product_categories_company_name_unique');
            $table->index(['company_id', 'parent_id'], 'pos_product_categories_company_parent_idx');
        });

        // ---- pos_products (Phase 6b + 4.9 delivery_price) ---------
        // delivery_price added by Phase 4.9 — NULL means "no
        // delivery markup, use base_price". Product::priceFor()
        // centralises the channel-aware lookup.
        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('pos_product_categories')->nullOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->decimal('base_price', 12, 3);
            $table->decimal('delivery_price', 12, 3)->nullable();
            $table->string('stock_mode', 16)->default('untracked');
            // Phase D2 — unit-mode LOW STOCK badge threshold (NULL = no badge).
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->decimal('cost_price', 12, 3)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            // Phase D2 — §5.5.3 tax-inclusive flag (display-only for now).
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no".
            $table->boolean('show_on_customer_tablet')->default(true);
            // P-G2 — internal items (cups/lids): never on the POS menu or
            // tablet, full stock participation.
            $table->boolean('is_internal')->default(false);
            // G1 — menu time-window ('HH:MM:SS', both NULL = always
            // available, start > end wraps midnight).
            $table->string('available_from', 8)->nullable();
            $table->string('available_until', 8)->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Sqlite UNIQUE accepts multiple NULLs natively, so
            // a plain unique is the faithful test mirror of the
            // Postgres partial-unique-when-not-null index.
            $table->unique(['company_id', 'sku'], 'pos_products_company_sku_unique');
            $table->unique(['company_id', 'barcode'], 'pos_products_company_barcode_unique');
        });

        // ---- pos_addon_groups + pos_addons + pivot (Phase 4.9) ---
        // Blueprint §5.5.4 + §10.4. selection_mode = single|multi.
        // is_global=true means the group applies to every product;
        // pivot table covers product-specific attachments.
        // ingredient_id is a placeholder until Phase 5 wires the FK.
        Schema::create('pos_addon_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            // v2 #6 — product-unique add-ons: a group privately owned by one
            // product (NULL = shared/global). No FK in the test schema to keep
            // table-create order flexible; the live migration has the real FK.
            $table->unsignedBigInteger('owner_product_id')->nullable();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('selection_mode', 16)->default('single');
            // Phase B — selection constraints (NULL = unbounded; min>=1 = required).
            $table->unsignedSmallInteger('min_selections')->nullable();
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->boolean('is_global')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_addon_groups_company_name_unique');
        });

        Schema::create('pos_addons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('add_on_group_id')->constrained('pos_addon_groups')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->decimal('price_delta', 12, 3)->default(0);
            // Phase B — pre-selected option in the POS customize sheet.
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_qty', 10, 3)->nullable();
            $table->string('ingredient_unit', 16)->nullable();
            // P-G3 — the add-on IS this product (consumes its real stock).
            $table->unsignedBigInteger('linked_product_id')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_addon_group_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('add_on_group_id')->constrained('pos_addon_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['add_on_group_id', 'product_id'], 'pos_addon_group_products_unique');
        });

        // Phase B — category-level group binding ("the more specific
        // binding wins" resolution happens device-side as a union).
        Schema::create('pos_addon_group_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('add_on_group_id')->constrained('pos_addon_groups')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('pos_product_categories')->cascadeOnDelete();
            $table->unique(['add_on_group_id', 'category_id'], 'pos_addon_group_categories_unique');
        });

        // ---- pos_floors (Phase 5) ---------------------------------
        Schema::create('pos_floors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'name'], 'pos_floors_branch_name_unique');
        });

        // ---- pos_tables (Phase 5 + 5.5 position columns) ----------
        // position_x/y/width/height come from Phase 5.5 — visual
        // floor planner. NULL = "not placed yet" / "use shape
        // default". The list-view tests never read these so they
        // stayed unread, but the planner tests assert round-trips
        // through the new bulk-layout endpoint.
        Schema::create('pos_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('floor_id')->constrained('pos_floors')->cascadeOnDelete();
            $table->string('label', 32);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->unsignedSmallInteger('min_party')->nullable();
            $table->unsignedSmallInteger('max_party')->nullable();
            $table->string('shape', 24)->default('square');
            $table->text('notes')->nullable();
            $table->string('qr_token', 64)->unique();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedSmallInteger('position_x')->nullable();
            $table->unsignedSmallInteger('position_y')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['floor_id', 'label'], 'pos_tables_floor_label_unique');
        });

        // ---- Spatie permission tables -----------------------------
        // Table names + team-scoping must match config/permission.php
        // exactly. Without `team_id`, every $user->can() call would
        // look up roles in the wrong scope.
        Schema::create('pos_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('pos_roles', function (Blueprint $table): void {
            $table->id();
            // teams=true: roles are scoped by team_id. Nullable so
            // platform-team roles can use team_id=0 vs merchant roles
            // team_id=company_id.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            // Phase 4.8 — flag the seeder-created defaults so the
            // role-builder UI hides delete + lock rename.
            $table->boolean('is_system')->default(false);
            $table->text('description')->nullable();
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('pos_model_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('pos_permissions')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type'], 'pos_model_has_permissions_primary');
        });

        Schema::create('pos_model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('pos_roles')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'role_id', 'model_id', 'model_type'], 'pos_model_has_roles_primary');
        });

        Schema::create('pos_role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('pos_permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('pos_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'pos_role_has_permissions_primary');
        });

        // ---- personal_access_tokens (Lane A — Android bridge) -----
        // Sanctum's DEFAULT table name (unprefixed). The project
        // intentionally collapsed onto the shared
        // `personal_access_tokens` table in charity_db so multiple
        // apps can share it via polymorphic `tokenable_type`.
        // See pos_admin AppServiceProvider for the original
        // architectural note.
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        // ---- pos_devices (Lane A — Android bridge, read-only on merchant) -
        // Schema owned by pos_admin. The merchant test mirror only
        // carries the columns pos_merchant actually reads (resolving
        // the device from a Sanctum token → tenant context).
        Schema::create('pos_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('pos_companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('kiosk_id')->nullable();
            $table->string('device_type', 32)->default('cashier');
            $table->string('status', 32)->default('registered');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- pos_device_activation_tokens (Lane A — Android bridge) -
        // One-shot codes the admin generates for a registered
        // device; the Android app exchanges one for a Sanctum PAT
        // at activation time. Soft-revoke via revoked_at, single-use
        // via used_at.
        Schema::create('pos_device_activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('pos_devices')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // ---- pos_suppliers + pos_ingredients + pos_branch_stock + pos_stock_movements (Phase 5a) ---
        Schema::create('pos_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_suppliers_company_name_unique');
        });

        Schema::create('pos_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('unit', 16);
            // Phase A (Additions §2.3) — the piece model.
            $table->string('piece_unit_label', 32)->nullable();
            $table->string('piece_unit_label_ar', 32)->nullable();
            $table->decimal('units_per_piece', 14, 4)->nullable();
            $table->boolean('allow_fractional_pieces')->default(true);
            $table->decimal('default_unit_cost', 12, 3)->default(0);
            $table->decimal('min_stock_threshold', 12, 3)->nullable();
            $table->foreignId('primary_supplier_id')->nullable()->constrained('pos_suppliers')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_ingredients_company_name_unique');
        });

        // v2 #13 — per-ingredient alternate units (base unit + factor).
        Schema::create('pos_ingredient_units', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('name_ar', 32)->nullable();
            $table->decimal('factor', 14, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['ingredient_id', 'name'], 'pos_ingredient_units_ingredient_name_unique');
        });

        Schema::create('pos_branch_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'ingredient_id'], 'pos_branch_stock_branch_ingredient_unique');
        });

        // P-G4 — central company ingredient pool: one row per (company,
        // ingredient), moved by the branch_id-NULL rows of the movements
        // ledger below. Mirrors pos_product_stock for ingredients.
        Schema::create('pos_ingredient_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'ingredient_id'], 'pos_ingredient_stock_company_ingredient_unique');
        });

        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            // P-G4 — NULL = the central company pool; set = a branch.
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->string('movement_type', 32);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['branch_id', 'occurred_at'], 'pos_stock_movements_branch_occurred_idx');
            $table->index(['ingredient_id', 'occurred_at'], 'pos_stock_movements_ingredient_occurred_idx');
        });

        // Phase A (Additions §2.4) — purchase batches: pieces + total paid +
        // the batch ratio, linked to the restock movement they produced.
        Schema::create('pos_ingredient_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('pos_suppliers')->nullOnDelete();
            $table->decimal('pieces_received', 12, 3)->nullable();
            $table->decimal('units_received', 12, 3);
            $table->decimal('total_paid', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 6)->default(0);
            $table->decimal('units_per_piece_at_purchase', 14, 4)->nullable();
            $table->boolean('is_loose')->default(false);
            $table->foreignId('stock_movement_id')->nullable()->constrained('pos_stock_movements')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
            $table->index(['company_id', 'ingredient_id', 'occurred_at'], 'pos_ingredient_purchases_company_ingredient_idx');
        });

        // Phase A (Additions §2.8) — day-end stock counts (header + lines).
        Schema::create('pos_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->timestamp('counted_at')->useCurrent();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'counted_at'], 'pos_stock_counts_company_branch_counted_idx');
        });

        Schema::create('pos_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('pos_stock_counts')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('counted_pieces', 12, 3)->nullable();
            $table->decimal('counted_units', 12, 3);
            $table->decimal('expected_units', 12, 3);
            $table->decimal('variance_units', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained('pos_stock_movements')->nullOnDelete();
            $table->unique(['stock_count_id', 'ingredient_id'], 'pos_stock_count_lines_count_ingredient_unique');
        });

        // ---- pos_product_recipes + pos_product_recipe_versions (Phase 5b) ---
        // Recipe lines unique per (product, ingredient).
        // Versions append-only snapshots of the PRE-edit state.
        Schema::create('pos_product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_at_set', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'ingredient_id'], 'pos_product_recipes_product_ingredient_unique');
        });

        Schema::create('pos_branch_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->decimal('stock_qty', 12, 3)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        // ---- pos_product_components (P-G2 physical items) ---
        // Per ONE unit sold of product_id, consume quantity of each
        // component (unit-mode products: cups, lids...). Mirrors
        // pos_admin's 2026_07_16_010000 migration.
        Schema::create('pos_product_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['product_id', 'component_product_id'], 'pos_product_components_pair_unique');
        });

        // ---- pos_product_stock + pos_product_stock_movements (Phase 7) ---
        // Central company unit-product pool + the product-units ledger.
        Schema::create('pos_product_stock', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'product_id'], 'pos_product_stock_company_product_unique');
        });

        Schema::create('pos_product_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->string('movement_type', 32);
            $table->decimal('quantity', 12, 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'product_id', 'occurred_at'], 'pos_product_stock_mov_company_product_idx');
            $table->index(['branch_id', 'occurred_at'], 'pos_product_stock_mov_branch_idx');
        });

        Schema::create('pos_product_recipe_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->text('recipe_json');
            $table->foreignId('edited_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('edited_at')->useCurrent();
        });

        // ---- pos_productions + pos_production_lines (P-G1) ---
        // Kitchen production batches for cooked products. Written
        // exclusively by pos_api; this app reads them for the
        // Production history page. Mirrors pos_admin's
        // 2026_07_14_010000_create_pos_productions_tables migration.
        Schema::create('pos_productions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('status', 16)->default('in_progress');
            $table->foreignId('started_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('finished_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('cancelled_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('cancel_approved_by_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // P-G1.5 — the chef's per-batch expiry (NULL = never expires).
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'started_at'], 'pos_productions_company_branch_idx');
            $table->index(['branch_id', 'status'], 'pos_productions_branch_status_idx');
        });

        Schema::create('pos_production_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_id')->constrained('pos_productions')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_at_time', 16);
            $table->boolean('is_extra')->default(false);
            $table->timestamps();
            $table->index(['production_id'], 'pos_production_lines_production_idx');
            $table->index(['ingredient_id'], 'pos_production_lines_ingredient_idx');
        });

        // ---- pos_waste_records + pos_restock_requests +
        //      pos_restock_request_lines (Phase 5c) ---
        // Waste rows mirror to stock_movements via polymorphic
        // reference. Restock requests + lines drive the branch ↔
        // HQ replenishment workflow; allocation writes stock
        // movements of type=restock referencing the request.
        Schema::create('pos_waste_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            // Always POSITIVE here; the mirrored stock_movement is signed-negative.
            $table->decimal('quantity', 12, 3);
            $table->string('reason', 32);
            $table->string('unit_at_set', 16);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('pos_restock_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // company_id denormalised so tenant-wide list queries
            // skip the join through branches.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_restock_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restock_request_id')->constrained('pos_restock_requests')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity_requested', 12, 3);
            $table->decimal('quantity_allocated', 12, 3)->default(0);
            $table->string('unit_at_set', 16);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['restock_request_id', 'ingredient_id'], 'pos_restock_request_lines_request_ingredient_unique');
        });

        // ---- pos_branch_transfers + lines (branch transfers, §5.6) ---
        // Immediate atomic stock move between two branches; each line writes a
        // paired transfer_out (source) + transfer_in (destination) movement.
        Schema::create('pos_branch_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('transferred_at')->useCurrent();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_branch_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_transfer_id')->constrained('pos_branch_transfers')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_at_set', 16);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->timestamps();
            $table->unique(['branch_transfer_id', 'ingredient_id'], 'pos_branch_transfer_lines_transfer_ingredient_unique');
        });

        // ---- pos_customers + pos_customer_vehicle_plates (Phase 6a) ---
        // Per-merchant customer book. Phone is the natural lookup
        // key at the POS; the (company_id, phone) unique constraint
        // makes find-or-create a single round-trip.
        // Plates live in a sibling LINK table (P-F2: many-to-many —
        // one customer ↔ many plates AND one plate ↔ many customers)
        // with company_id denormalised so the drive-thru
        // "plate → customer(s)" lookup is a single index hit + FK follow.
        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32);
            // Phase D3 -- optional CRM profile fields (blueprint
            // §5.7.2): birthday indicator + free-form tag strings
            // (VIP, Blocked...). tags_json NULL means "no tags".
            $table->date('date_of_birth')->nullable();
            $table->json('tags_json')->nullable();
            // Phase 6b -- denormalised wallet balance kept in
            // lock-step with SUM(wallet_ledger). Points moved to
            // pos_loyalty_accounts.point_balance in the loyalty
            // refactor (a customer can hold points under several
            // rules); the wallet (store credit) stays here.
            $table->decimal('wallet_balance', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'phone'], 'pos_customers_company_phone_unique');
        });

        Schema::create('pos_customer_vehicle_plates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('pos_customers')->cascadeOnDelete();
            // Denormalised from the parent customer; powers the
            // per-company link unique + lookup index without a join
            // through customers.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('plate_number', 32);
            $table->timestamps();
            // P-F2 — many-to-many: one row per customer↔plate LINK
            // (a family car shared by several loyalty members), plus a
            // plain index serving the "plate → customer(s)" hot path.
            $table->unique(['company_id', 'customer_id', 'plate_number'], 'pos_cvp_company_customer_plate_unique');
            $table->index(['company_id', 'plate_number'], 'pos_cvp_company_plate_index');
        });

        // ---- Loyalty: rules + accounts + transactions (blueprint §5.8 / §10.6) ---
        // Multi-rule loyalty. A company defines visit_based (stamp
        // card) and/or spend_based (points) rules, multiple active
        // in parallel. Each customer gets ONE account per rule
        // holding stamp_count + point_balance. An append-only
        // transactions ledger records every earn/redeem/adjust/
        // expire with running balances so a history view never
        // re-sums and drift is caught instantly.
        // (Replaces the Phase 6b single-config + point-ledger model.)
        Schema::create('pos_loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            // LoyaltyRuleType: visit_based / spend_based.
            $table->string('type', 32);
            // Per-type config + restrictions (eligible products /
            // categories / branches / days-hours, max redemption,
            // customer-tag). sqlite mirror — production jsonb.
            $table->text('config_json')->nullable();
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            // active / paused.
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('pos_customers')->cascadeOnDelete();
            $table->foreignId('loyalty_rule_id')->constrained('pos_loyalty_rules')->cascadeOnDelete();
            $table->integer('stamp_count')->default(0);
            $table->integer('point_balance')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            // One account per customer per rule.
            $table->unique(['customer_id', 'loyalty_rule_id'], 'pos_loyalty_accounts_customer_rule_unique');
        });

        Schema::create('pos_loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Denormalised so the §5.11.8 Customer Report can sum
            // by company + window without a join through accounts.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('loyalty_account_id')->constrained('pos_loyalty_accounts')->cascadeOnDelete();
            // LoyaltyTransactionType: earn / redeem / adjust / expire.
            $table->string('type', 32);
            // SIGNED deltas; a row may move points OR stamps OR both.
            $table->integer('points_delta')->default(0);
            $table->integer('stamps_delta')->default(0);
            $table->integer('balance_after_points')->default(0);
            $table->integer('balance_after_stamps')->default(0);
            $table->text('reason')->nullable();
            // Phase 8 wires earn/redeem to the triggering sale.
            // Nullable + unconstrained for now (manual adjustments
            // have no order).
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- pos_customer_wallet_ledger (Phase 6b — store credit) ---
        // SEPARATE from loyalty (not in the blueprint loyalty model).
        // Append-only OMR ledger with balance_after; kept in lock-step
        // with pos_customers.wallet_balance. Unchanged by the loyalty
        // refactor.
        Schema::create('pos_customer_wallet_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('pos_customers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('entry_type', 32);
            // SIGNED OMR decimal:3.
            $table->decimal('amount_delta', 12, 3);
            $table->decimal('balance_after', 12, 3);
            $table->text('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- pos_delivery_providers + pos_product_delivery_prices (Phase 6c) ---
        // Per-merchant 3rd-party delivery providers (Talabat,
        // Otlob, ...) with per-product price overrides. Price
        // resolution chain at POS time:
        //   override -> products.delivery_price -> products.base_price
        Schema::create('pos_delivery_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('color', 7)->nullable();
            // P-G7 — mirrors pos_admin's 2026_07_20_010000 migration.
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_delivery_providers_company_name_unique');
        });

        // ---- pos_taxes (company-level taxes the POS fetches via config) ----
        Schema::create('pos_taxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->decimal('rate_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_taxes_company_name_unique');
        });

        // v2 #7 — custom expense categories (company-managed).
        Schema::create('pos_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->string('key', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'key'], 'pos_expense_categories_company_key_unique');
        });

        // v2 #14 — per-company merchant POS policy (e.g. order_cancel_positions).
        Schema::create('pos_company_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key'], 'pos_company_settings_company_key_unique');
        });

        // P-F8 — server-owned order-number counters (mirrors pos_admin's
        // 2026_07_12_010000 migration; allocated by pos_api, not this app).
        // branch_id NULL = company scope; seq_date NULL = continuous counter.
        Schema::create('pos_order_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('seq_date')->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'seq_date'], 'pos_order_sequences_lookup_idx');
        });
        // The same COALESCE functional unique index as live Postgres — NULL
        // branch/date coalesce to impossible sentinels so "one row per
        // scope" holds despite sqlite/Postgres treating NULLs as distinct
        // in plain unique constraints.
        DB::statement(
            'CREATE UNIQUE INDEX pos_order_sequences_scope_unique ON pos_order_sequences '.
            "(company_id, COALESCE(branch_id, 0), COALESCE(seq_date, '1970-01-01'))"
        );

        Schema::create('pos_product_delivery_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->foreignId('delivery_provider_id')->constrained('pos_delivery_providers')->cascadeOnDelete();
            // Denormalised from product so tenant-scoped reports
            // skip the join, and the Action layer can cross-
            // check that product.company == provider.company.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->decimal('price', 12, 3);
            $table->timestamps();
            $table->unique(['product_id', 'delivery_provider_id'], 'pos_product_delivery_prices_product_provider_unique');
        });

        // ---- pos_orders + pos_order_items + pos_order_item_addons (Phase 7a) ---
        // Transactional spine. Snapshot columns (product/price/
        // recipe) freeze the state at order-write time so a later
        // catalogue edit doesn't retroactively shift historical
        // totals. JSON columns mirror as TEXT here (sqlite has
        // no jsonb) — production Postgres uses jsonb.
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->string('order_type', 32);
            $table->string('status', 32)->default('open');
            // Phase B — void reason snapshot (FK-less in the test schema for
            // create-order flexibility; the live migration has the real FK).
            $table->unsignedBigInteger('void_reason_id')->nullable();
            $table->string('void_reason_label', 64)->nullable();
            $table->string('source', 32);
            $table->string('plate_number', 32)->nullable();
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            // Phase B — cached sum of pos_order_comps for this order.
            $table->decimal('comp_total', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('grand_total', 12, 3)->default(0);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->string('client_event_id', 64)->nullable()->unique('pos_orders_client_event_id_unique');
            $table->text('note')->nullable();
            // P-F8 — the printed receipt number (prefix + zero-padded
            // counter, e.g. "KLD-0042"); NULL for unnumbered orders.
            $table->string('receipt_number', 24)->nullable();
            // P-G7 — delivery-provider lifecycle (mirrors pos_admin's
            // 2026_07_20_010000 migration): provider linkage + the
            // Proceed-popup fields + the punch/confirm money snapshot.
            // FK-less provider/user ids here for create-order flexibility
            // (the live migration has real FKs), like void_reason_id above.
            $table->unsignedBigInteger('delivery_provider_id')->nullable();
            $table->string('delivery_provider_name', 64)->nullable();
            $table->string('delivery_reference', 64)->nullable();
            $table->string('delivery_customer_phone', 32)->nullable();
            $table->string('delivery_driver_phone', 32)->nullable();
            $table->decimal('delivery_commission_percent', 5, 2)->nullable();
            $table->decimal('delivery_expected_payout', 12, 3)->nullable();
            $table->decimal('delivery_received_amount', 12, 3)->nullable();
            $table->decimal('delivery_variance', 12, 3)->nullable();
            $table->timestamp('delivery_punched_at')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();
            $table->unsignedBigInteger('delivery_confirmed_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'receipt_number'], 'pos_orders_company_receipt_idx');
            $table->index(['company_id', 'delivery_provider_id'], 'pos_orders_company_provider_idx');
        });

        Schema::create('pos_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('pos_products')->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->decimal('qty', 12, 3)->default('1.000');
            $table->decimal('unit_price_snapshot', 12, 3);
            $table->decimal('line_discount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3);
            // sqlite mirror — production is jsonb on Postgres.
            $table->text('recipe_snapshot_json')->nullable();
            $table->string('status', 32)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_order_item_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('pos_order_items')->cascadeOnDelete();
            $table->foreignId('add_on_id')->nullable()->constrained('pos_addons')->nullOnDelete();
            $table->string('add_on_name_snapshot');
            $table->decimal('price_delta_snapshot', 12, 3);
            $table->text('ingredient_snapshot_json')->nullable();
            // P-G3 — product-as-add-on freeze (consumption + reporting).
            $table->unsignedBigInteger('linked_product_id')->nullable()->index();
            $table->text('product_snapshot_json')->nullable();
            $table->timestamps();
        });

        // ---- pos_discounts + pos_discount_targets (Phase 6d) ---
        // Per-merchant discount rules. The 6-axis applicability
        // predicate (status / validity / day-of-week / time /
        // branch-scope / scope+targets) is enforced in the
        // evaluateDiscounts() pure function, not in SQL.
        Schema::create('pos_discounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('scope', 32);
            $table->string('amount_type', 32);
            $table->decimal('amount', 12, 3);
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            // Bitmask Sun=1..Sat=64; NULL = every day.
            $table->unsignedTinyInteger('dayofweek_mask')->nullable();
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();
            // sqlite mirror: text fallback for jsonb on pg.
            $table->text('branch_scope_json')->nullable();
            $table->boolean('stackable')->default(false);
            $table->boolean('requires_manager_approval')->default(false);
            // P-F4: order-scope rules only — true = device auto-applies the
            // rule to every qualifying order. Forced TRUE by the write
            // actions for product/category scopes (always automatic).
            $table->boolean('auto_apply')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_discount_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_id')->constrained('pos_discounts')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['discount_id', 'target_type', 'target_id'], 'pos_discount_targets_unique');
        });

        // ---- pos_offers (P-F9) ---
        // Merchant offers / promotions: type + type-specific config JSON
        // (the pos_loyalty_rules pattern). Shared applicability axes
        // mirror pos_discounts; bundle is always cashier-picked
        // (auto_apply forced false by the write actions). Money inside
        // config is integer BAISAS.
        Schema::create('pos_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->string('type', 24);
            $table->json('config');
            $table->boolean('auto_apply')->default(true);
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            // Bitmask Sun=1..Sat=64; NULL = every day.
            $table->smallInteger('dayofweek_mask')->nullable();
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();
            // sqlite mirror: text fallback for jsonb on pg.
            $table->text('branch_scope_json')->nullable();
            $table->smallInteger('max_per_order')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- pos_order_discounts (Phase 8.10) ---
        // Per-order discount-application records written by the pos_api sale
        // pipeline at order.create. Feeds the §5.11.7 Discount Report's
        // by-RULE breakdown. name/type snapshotted for rename-safe history;
        // discount_id NULL = manual discount, order_item_id NULL = order-level.
        Schema::create('pos_order_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('pos_order_items')->nullOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('pos_discounts')->nullOnDelete();
            // P-F9: which pos_offers promotion granted this amount
            // (null = a plain discount application).
            $table->foreignId('offer_id')->nullable()->constrained('pos_offers')->nullOnDelete();
            $table->string('name_snapshot');
            $table->string('amount_type_snapshot', 32)->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            // P-F4: cashier's free-text reason for a manual / custom
            // discount (trimmed + capped to 160 by the pos_api writer).
            $table->string('reason', 160)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // ---- Phase B — void/comp reason masters + order comps ----
        Schema::create('pos_void_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->boolean('affects_inventory')->default(false);
            $table->boolean('requires_manager')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'pos_void_reasons_company_code_unique');
        });

        Schema::create('pos_comp_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->decimal('max_amount', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'pos_comp_reasons_company_code_unique');
        });

        Schema::create('pos_order_comps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('pos_order_items')->nullOnDelete();
            $table->foreignId('comp_reason_id')->nullable()->constrained('pos_comp_reasons')->nullOnDelete();
            $table->string('reason_code_snapshot', 32);
            $table->string('reason_name_snapshot', 64);
            // P-F5 — a gifted line: 100% write-off, NO reason, NO cap.
            $table->boolean('is_gift')->default(false);
            $table->decimal('amount', 12, 3)->default(0);
            $table->foreignId('approved_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // ---- pos_payments + pos_shifts (Phase 7a) ---
        // Payments support split tender + Soft POS reconciliation.
        // Invariant (enforced by Phase 8 Action): SUM(payments
        // WHERE status=success) == order.grand_total for paid orders.
        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('pos_orders')->cascadeOnDelete();
            $table->string('method', 32);
            $table->decimal('amount', 12, 3);
            $table->decimal('change_given', 12, 3)->nullable();
            $table->string('softpos_reference', 64)->nullable();
            $table->string('softpos_auth_code', 32)->nullable();
            $table->string('status', 32)->default('success');
            $table->boolean('pending_reconciliation')->default(false);
            $table->foreignId('reconciled_by_admin_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('captured_at')->useCurrent();
            $table->timestamps();
        });

        // v2 #17 — per-sale commission breakdown (one row per party: platform /
        // bank / other + merchant residual). Written by pos_api; the merchant
        // payout report + admin settlement read it. Schema owned by pos_admin.
        // P-G7 — per-merchant commission profile + share lines (mirrors the
        // pos_admin DDL; pos_api applies these at pay time, the merchant's
        // delivery confirmation applies them at confirmation time).
        Schema::create('pos_commission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->decimal('merchant_percent', 5, 2)->default(100);
            $table->timestamps();
        });

        Schema::create('pos_commission_shares', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->string('party_type', 20);
            $table->string('label', 120);
            $table->decimal('percent', 5, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_sale_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->string('party_type', 20);
            $table->string('party_label', 120);
            $table->decimal('percent', 5, 2);
            $table->decimal('gross_amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('client_event_id', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->unique(['order_id', 'sort_order'], 'pos_sale_commissions_order_sort_unique');
            $table->index(['company_id', 'occurred_at'], 'pos_sale_commissions_company_occurred_idx');
        });

        // v2 #18 — POS-owned charity round-up donations (written by pos_api; the
        // merchant + admin round-up reports read it). Schema owned by pos_admin.
        Schema::create('pos_roundup_donations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 12, 3);
            $table->string('status', 30)->default('pending');
            $table->string('source', 30)->default('pos_roundup');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        // v2 #17 Phase B — merchant payouts (created + settled by pos_admin; the
        // merchant portal reads its own). Schema owned by pos_admin.
        Schema::create('pos_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->string('status', 20)->default('pending');
            $table->decimal('gross_amount', 12, 3)->default(0);
            $table->decimal('platform_amount', 12, 3)->default(0);
            $table->decimal('bank_amount', 12, 3)->default(0);
            $table->decimal('other_amount', 12, 3)->default(0);
            $table->decimal('net_amount', 12, 3)->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // Shifts: cashier opens with a float, closes with a count,
        // variance is the audit trigger. Tied to a device (which
        // POS terminal) + a staff (which cashier).
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 3)->default(0);
            $table->decimal('closing_cash', 12, 3)->nullable();
            $table->decimal('expected_cash', 12, 3)->nullable();
            $table->decimal('variance', 12, 3)->nullable();
            $table->string('status', 32)->default('open');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ---- Expenses (Phase 6 backfill — blueprint §5.10 / §10.8) --
        // POS-captured expenses; the merchant portal reviews them.
        // Schema owned by pos_admin's 2026_06_07_010000 migration.
        Schema::create('pos_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->nullOnDelete();
            $table->string('category', 32);
            $table->decimal('amount', 12, 3);
            $table->text('note')->nullable();
            $table->string('receipt_photo_path')->nullable();
            $table->foreignId('logged_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->foreignId('logged_by_portal_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('logged_at')->useCurrent();
            $table->string('status', 32)->default('recorded');
            $table->foreignId('reviewed_by_portal_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        // ---- Sessions (used by some auth integration tests) -------
        // Mirrors the Laravel default sessions table — pos_merchant
        // is configured to use session driver=array in tests so this
        // is rarely consulted, but creating it avoids surprises if a
        // future test flips to database sessions.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // ---- pos_saved_views (per-user filter presets) ---
        // Personal bookmarks scoped to (company_id, user_id). Not shared.
        Schema::create('pos_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('pos_users')->cascadeOnDelete();
            $table->string('view_key', 64);
            $table->string('name', 100);
            $table->json('filters')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'view_key', 'name'], 'pos_saved_views_user_key_name_unique');
            $table->index(['user_id', 'view_key'], 'pos_saved_views_user_key_idx');
        });

        // ---- messaging (P-G6) ---
        // Channel 1: portal -> POS devices (staff announcements + read
        // receipts; written here, served to devices by pos_api). Channel 2:
        // portal -> portal inbox. Mirrors pos_admin's
        // 2026_07_19_000000_create_pos_messaging_tables migration.
        Schema::create('pos_staff_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('target_branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('target_staff_id')->nullable()->constrained('pos_staff')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'created_at'], 'pos_staff_messages_company_created_idx');
            $table->index(['target_branch_id'], 'pos_staff_messages_branch_idx');
        });

        Schema::create('pos_staff_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_message_id')->constrained('pos_staff_messages')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['staff_message_id', 'staff_id'], 'pos_staff_message_reads_unique');
        });

        Schema::create('pos_portal_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('target_user_id')->nullable()->constrained('pos_users')->cascadeOnDelete();
            $table->string('target_role', 64)->nullable();
            $table->foreignId('target_branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'pos_portal_messages_company_created_idx');
        });

        Schema::create('pos_portal_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_message_id')->constrained('pos_portal_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('pos_users')->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['portal_message_id', 'user_id'], 'pos_portal_message_reads_unique');
        });
    }

    public function down(): void
    {
        // Production safety (CRITICAL): never drop shared tables on a real DB.
        // pos_companies, pos_users, sessions, … are owned by pos_admin / charity
        // on the shared charity_db — dropping them here would be catastrophic.
        // Only ever run against the :memory: test DB.
        if (! app()->environment('testing')) {
            return;
        }

        // Drop in reverse dependency order. Tests use :memory: so
        // this is essentially never called, but symmetry is cheap.
        Schema::dropIfExists('pos_order_sequences');
        Schema::dropIfExists('pos_saved_views');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('pos_role_has_permissions');
        Schema::dropIfExists('pos_model_has_roles');
        Schema::dropIfExists('pos_model_has_permissions');
        Schema::dropIfExists('pos_roles');
        Schema::dropIfExists('pos_permissions');
        Schema::dropIfExists('pos_staff');
        Schema::dropIfExists('pos_password_reset_tokens');
        Schema::dropIfExists('pos_audit_logs');
        Schema::dropIfExists('pos_branches');
        Schema::dropIfExists('pos_users');
        Schema::dropIfExists('pos_companies');
    }
};
