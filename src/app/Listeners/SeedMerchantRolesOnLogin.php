<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Guarantee a merchant owner is never locked out of their own portal.
 *
 * pos_admin's CreateMerchantUserAction creates the first merchant user
 * with an EMPTY `merchant_super_admin` role — it cannot seed this app's
 * permission catalogue because the MerchantPermission enum and
 * SeedMerchantRolesAction live HERE, not in pos_admin. Left unseeded,
 * the owner logs in holding a role with zero permissions, every gated
 * endpoint 403s, and there is no in-app way out (the role-builder and
 * portal-user pages are themselves gated).
 *
 * On every merchant login we run the idempotent
 * {@see SeedMerchantRolesAction}, which (re)creates the 5 default roles
 * under the company team scope and FORCE-syncs `merchant_super_admin`
 * to the full permission set. Non-owner roles keep any custom edits —
 * only SuperAdmin is force-resynced, matching the seeder's "owner can
 * never lock themselves out" contract.
 *
 * Deliberately SYNCHRONOUS (not ShouldQueue): AuthenticatedSession
 * Controller reads the user's permissions into the login response
 * immediately after Auth::login fires this event, so the seed must
 * finish in-band for the SPA's first paint to see them.
 */
final readonly class SeedMerchantRolesOnLogin
{
    public function __construct(
        private SeedMerchantRolesAction $seedRoles,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        // Only merchant rows carry a company team scope to seed.
        // Platform-admin rows (which cannot reach this portal anyway)
        // and any company-less row are skipped.
        if (! $user instanceof User || ! $user->isMerchantUser()) {
            return;
        }

        if ($user->company_id === null) {
            return;
        }

        $this->seedRoles->handle((int) $user->company_id);
    }
}
