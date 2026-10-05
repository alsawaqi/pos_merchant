<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a branch-transfer create. The source branch is the route
 * ({branch:uuid}); the body names the destination + the lines. Tenant
 * ownership of both branches + ingredients is enforced in the action.
 */
class CreateBranchTransferRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_branch_uuid' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_uuid' => ['required', 'uuid'],
            'lines.*.quantity' => ['required_without:lines.*.containers', 'nullable', 'numeric', 'gt:0'],
            // LAUNCH review add-on (D1) — by container: the amount fills in as
            // pieces × size and may only be lowered (checked in the action).
            'lines.*.containers' => ['sometimes', 'array', 'max:20'],
            'lines.*.containers.*.container_uuid' => ['required', 'string', 'max:64'],
            'lines.*.containers.*.pieces' => ['required', 'numeric', 'gt:0', 'max:999999.9999'],
            'lines.*.containers.*.leaf_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            // #13 — per-line entered unit (alt-unit name, or null = base);
            // converted to base before the over-draw check + the movements.
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
        ];
    }
}
