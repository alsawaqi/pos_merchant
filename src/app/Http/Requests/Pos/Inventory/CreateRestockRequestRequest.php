<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/branches/{branch:uuid}/restock-requests.
 *
 * Branch is route-bound and tenant-checked. Cross-tenant
 * ingredient + duplicate-ingredient validation happens in the
 * Action (single source of truth).
 *
 * lines.* must hold a positive quantity_requested. Per-line
 * notes are optional (the requester may add e.g. "we've been
 * out for 3 days" on a specific item). Max 50 lines per
 * request — same generous upper bound as recipes.
 */
class CreateRestockRequestRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            'lines.*.quantity_requested' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
            // Parent-level note — context the requester wants HQ
            // to see (e.g. "for the weekend rush").
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
