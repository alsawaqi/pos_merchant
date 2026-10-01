<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Support\Facades\DB;

/**
 * May this merchant's portal users sign in and work? (LAUNCH-P1 P1-13,
 * owner decision B7 2026-09-30.)
 *
 * Only `suspended` and `inactive` block. A merchant in `onboarding` works
 * normally — that is when the owner sets up the menu, staff and branches
 * before going live. A missing or deleted company also blocks.
 *
 * Each refusal carries its own code and message, so an onboarding or a
 * closed merchant is never told "Account suspended".
 */
final class CompanyAccess
{
    /**
     * @return array{code: string, message: string}|null null = access allowed
     */
    public static function denial(mixed $companyId): ?array
    {
        if ($companyId === null) {
            return self::unavailable();
        }

        $company = DB::table('pos_companies')
            ->where('id', $companyId)
            ->first(['status', 'deleted_at']);

        if ($company === null || $company->deleted_at !== null) {
            return self::unavailable();
        }

        return match ((string) $company->status) {
            'suspended' => ['code' => 'company_suspended', 'message' => 'Account suspended.'],
            'inactive' => ['code' => 'company_inactive', 'message' => 'This merchant account is closed. Contact MITHQAL support.'],
            default => null,
        };
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function unavailable(): array
    {
        return ['code' => 'company_unavailable', 'message' => 'This merchant account is not available. Contact MITHQAL support.'];
    }
}
