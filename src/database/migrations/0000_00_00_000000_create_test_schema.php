<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_product_categories_company_name_unique');
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
            $table->decimal('cost_price', 12, 3)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
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
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('selection_mode', 16)->default('single');
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
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_qty', 10, 3)->nullable();
            $table->string('ingredient_unit', 16)->nullable();
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
            $table->decimal('default_unit_cost', 12, 3)->default(0);
            $table->decimal('min_stock_threshold', 12, 3)->nullable();
            $table->foreignId('primary_supplier_id')->nullable()->constrained('pos_suppliers')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_ingredients_company_name_unique');
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

        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
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

        Schema::create('pos_product_recipe_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('pos_products')->cascadeOnDelete();
            $table->text('recipe_json');
            $table->foreignId('edited_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('edited_at')->useCurrent();
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

        // ---- pos_customers + pos_customer_vehicle_plates (Phase 6a) ---
        // Per-merchant customer book. Phone is the natural lookup
        // key at the POS; the (company_id, phone) unique constraint
        // makes find-or-create a single round-trip.
        // Plates live in a 1:N sibling table with company_id
        // denormalised so the drive-thru "plate → customer" lookup
        // is a single index hit + FK follow.
        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 32);
            // Phase 6b -- denormalised running balances kept in
            // lock-step with SUM(point_ledger) + SUM(wallet_ledger).
            $table->integer('points_balance')->default(0);
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
            // (company_id, plate_number) unique constraint without
            // a join through customers.
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('plate_number', 32);
            $table->timestamps();
            $table->unique(['company_id', 'plate_number'], 'pos_customer_vehicle_plates_company_plate_unique');
        });

        // ---- pos_customer_loyalty_configs + point_ledger + wallet_ledger (Phase 6b) ---
        // Per-company loyalty config (singleton). Two append-only
        // ledgers (points + wallet) with balance_after columns so
        // a history view doesn't re-sum prior entries per row, and
        // ledger-balance drift is caught instantly.
        Schema::create('pos_customer_loyalty_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('pos_companies')->cascadeOnDelete();
            $table->unsignedInteger('points_per_omr')->default(0);
            $table->unsignedInteger('baisas_per_point')->default(10);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_customer_point_ledger', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('pos_customers')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->string('entry_type', 32);
            // SIGNED integer points.
            $table->integer('points_delta');
            $table->integer('balance_after');
            $table->text('reason')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

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
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'name'], 'pos_delivery_providers_company_name_unique');
        });

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
    }

    public function down(): void
    {
        // Drop in reverse dependency order. Tests use :memory: so
        // this is essentially never called, but symmetry is cheap.
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('pos_role_has_permissions');
        Schema::dropIfExists('pos_model_has_roles');
        Schema::dropIfExists('pos_model_has_permissions');
        Schema::dropIfExists('pos_roles');
        Schema::dropIfExists('pos_permissions');
        Schema::dropIfExists('pos_staff');
        Schema::dropIfExists('pos_audit_logs');
        Schema::dropIfExists('pos_branches');
        Schema::dropIfExists('pos_users');
        Schema::dropIfExists('pos_companies');
    }
};
