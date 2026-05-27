<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\PermissionRegistrar;

/**
 * Projection of a merchant teammate {@see User} for the Portal
 * Users tab.
 *
 * Phase 4.8 change: `role` (single string) is replaced with
 * `roles` (array of strings) — users can now hold any number
 * of merchant roles, and the SPA's role chips render that
 * union. Backward-compatible `role` field is also returned for
 * one release as the first role for any frontend that hasn't
 * updated yet (purge it later when nothing reads it).
 *
 * Role resolution explicitly switches the spatie team_id to
 * this user's company before reading, so the response doesn't
 * accidentally pick up roles from whatever team was active at
 * serialise time.
 *
 * branch_scope is mirrored verbatim: NULL = all branches, array
 * = restricted to those ids.
 *
 * @mixin User
 */
class PortalUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->resolveRoles();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            // Backward-compatible single role — first one for
            // anyone still consuming the old shape. New
            // consumers should read `roles` instead.
            'role' => $roles[0] ?? null,
            'roles' => $roles,
            'branch_scope' => $this->branch_scope_json,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invited_by_admin_id' => $this->invited_by_admin_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveRoles(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $companyId = app(MerchantTenantContext::class)->id();
        if ($companyId === null) {
            return [];
        }

        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);
        try {
            return $this->getRoleNames()->values()->all();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
