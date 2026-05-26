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
 * Users tab. The role is resolved under the company team scope
 * explicitly so it doesn't accidentally read from whatever team
 * was active when the resource serialised.
 *
 * branch_scope is mirrored verbatim: NULL = all branches, array =
 * restricted to those ids. The frontend renders a chip + the count
 * accordingly.
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'role' => $this->resolveRole(),
            'branch_scope' => $this->branch_scope_json,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'invited_at' => $this->invited_at?->toIso8601String(),
            'invited_by_admin_id' => $this->invited_by_admin_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function resolveRole(): ?string
    {
        $registrar = app(PermissionRegistrar::class);
        $companyId = app(MerchantTenantContext::class)->id();
        if ($companyId === null) {
            return null;
        }

        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);
        try {
            return $this->getRoleNames()->first();
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }
    }
}
