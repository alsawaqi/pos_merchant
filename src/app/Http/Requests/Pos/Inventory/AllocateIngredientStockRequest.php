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
            'allocations.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
