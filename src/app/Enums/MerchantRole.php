<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Default merchant portal roles per blueprint §5.1.2.
 *
 * Custom roles can also be defined by the merchant Super Admin
 * once we ship a role-builder UI (Phase 7-ish). The 5 cases here
 * are the floor — every company gets all of them seeded when its
 * first portal user is created via
 * {@see \App\Actions\Admin\SeedMerchantRolesAction}.
 *
 * Naming convention: `merchant_*` prefix keeps these from
 * colliding with pos_admin's PlatformRole values (`platform_*`)
 * in the shared spatie permission tables.
 */
enum MerchantRole: string
{
    case SuperAdmin = 'merchant_super_admin';
    case Manager = 'merchant_manager';
    case InventoryManager = 'merchant_inventory_manager';
    case CashierSupervisor = 'merchant_cashier_supervisor';
    case Viewer = 'merchant_viewer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
