<?php

declare(strict_types=1);

use Spatie\Permission\DefaultTeamResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Spatie permission config for the merchant portal.
 *
 * MUST mirror pos_admin's `config/permission.php` for two
 * reasons:
 *
 *  1. The two apps share the same `charity_db`. The spatie
 *     tables there are prefixed `pos_*` (pos_roles, pos_permissions,
 *     etc.). Without these table-name overrides, spatie defaults
 *     to `roles` / `permissions` which don't exist in this DB
 *     and every role lookup explodes with a 42P01.
 *
 *  2. `teams=true` + `team_resolver=DefaultTeamResolver::class` is
 *     how we scope merchant roles to their company_id while keeping
 *     platform-admin roles under team_id=0. Toggling either off
 *     would silently cross the streams.
 *
 * The only intentional divergence from pos_admin is the cache key:
 * each app gets its own namespace so flushes on one side don't
 * thrash the other side's cache when both are deployed against
 * the same Redis store.
 */
return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'table_names' => [
        // All four pivots + the two base tables MUST use the
        // `pos_` prefix — that's the schema pos_admin migrated.
        'roles' => 'pos_roles',
        'permissions' => 'pos_permissions',
        'model_has_permissions' => 'pos_model_has_permissions',
        'model_has_roles' => 'pos_model_has_roles',
        'role_has_permissions' => 'pos_role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        // Merchant roles set team_id=company_id; platform-admin
        // roles set team_id=0. Same column, two namespaces.
        'team_foreign_key' => 'team_id',
    ],

    'register_permission_check_method' => true,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,

    // CRITICAL — keep `true` or merchant roles fall back to
    // global scope and the platform admin's `merchant_super_admin`
    // role would resolve for every company.
    'teams' => true,
    'team_resolver' => DefaultTeamResolver::class,

    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        // Distinct namespace from pos_admin so a flush on one
        // side doesn't invalidate the other's hot cache.
        'key' => 'pos_merchant.spatie.permission.cache',

        'store' => 'default',
    ],
];
