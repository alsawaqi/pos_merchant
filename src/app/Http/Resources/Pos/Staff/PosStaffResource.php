<?php

declare(strict_types=1);

namespace App\Http\Resources\Pos\Staff;

use App\Models\PosStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see PosStaff} row for the merchant portal.
 *
 * Deliberately omits:
 *   - pin_hash (one-way credential material, never leaves the
 *     server)
 *   - created_by_user_id (denormalised into `creator` below as
 *     id + name only, avoids leaking the creator's email)
 *
 * Includes the soft-delete timestamp so the UI can show "Re-hire"
 * affordances on terminated rows in a future Phase, even though
 * the default list query excludes them.
 *
 * @mixin PosStaff
 */
class PosStaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'phone' => $this->phone,
            'staff_code' => $this->staff_code,
            'position' => $this->position?->value,
            'status' => $this->status?->value,
            'branch' => [
                'id' => $this->branch_id,
                'name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            ],
            'creator' => [
                'id' => $this->created_by_user_id,
                'name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            ],
            'hired_at' => $this->hired_at?->toDateString(),
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
