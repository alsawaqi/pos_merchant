<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Role;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * Projection of a spatie {@see Role} for the merchant
 * role-builder UI.
 *
 * Exposes:
 *   - id, name, description, is_system — for the table + editor
 *   - permissions[]                    — the assigned permission
 *                                        string keys (resolved at
 *                                        serialise time so the
 *                                        Edit modal can pre-check
 *                                        boxes without a second
 *                                        round trip)
 *   - user_count                       — how many portal users
 *                                        currently hold this role
 *                                        (drives the "you can't
 *                                        delete a role still in
 *                                        use" UI affordance)
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => (bool) $this->is_system,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name')->all(),
                fn () => $this->permissions()->pluck('name')->all(),
            ),
            'user_count' => $this->users_count
                ?? $this->users()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
