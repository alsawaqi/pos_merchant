<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/allocate —
 * distribute the central warehouse pool out to branches.
 */
class AllocateIngredientStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.branch_uuid' => ['required', 'string', 'uuid'],
            // Fix order B-2 — a branch's share by container: rows of pieces and
            // a total (in amount_unit) that may be lowered, never raised.
            'allocations.*.quantity' => ['required_without:allocations.*.containers', 'nullable', 'numeric', 'gt:0', 'max:999999.999'],
            'allocations.*.containers' => ['sometimes', 'array', 'max:20'],
            'allocations.*.containers.*.container_uuid' => ['required', 'string', 'max:64'],
            'allocations.*.containers.*.pieces' => ['required', 'numeric', 'gt:0', 'max:999999.9999'],
            'allocations.*.containers.*.leaf_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'allocations.*.amount_unit' => ['nullable', 'string', 'max:32'],
            // LAUNCH item kind, F4 — the unit the amounts are typed in (kg, l,
            // a pack size, '@piece'); null = the stored unit. Converted by the
            // controller (IngredientUnitConverter).
            'unit' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
