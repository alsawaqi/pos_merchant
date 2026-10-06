<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH packaging add-on (fix order PK-B1, L2) — one save of a merchant's
 * packaging list of one order type at a time: a transaction-scoped Postgres
 * advisory lock keyed by (company, order type). The save reads the list it
 * replaces only after taking it, so concurrent saves end with the last one
 * (never a union of both, never a unique-index 500). SQLite (the test
 * database) runs one writer at a time and has no advisory locks.
 */
class OrderPackagingLock
{
    public const PREFIX = 'pos_order_packaging:';

    /** Must be called inside the save's transaction. */
    public function acquire(int $companyId, string $orderType): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::key($companyId, $orderType)]);
    }

    public static function key(int $companyId, string $orderType): string
    {
        return self::PREFIX.$companyId.':'.$orderType;
    }
}
