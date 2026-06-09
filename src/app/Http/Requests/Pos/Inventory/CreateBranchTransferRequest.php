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
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            // #13 — per-line entered unit (alt-unit name, or null = base);
            // converted to base before the over-draw check + the movements.
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
        ];
    }
}
