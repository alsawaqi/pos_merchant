<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/ingredients/{ingredient:uuid}/stock/adjust — signed
 * manual correction to the central warehouse pool (branch_uuid null) or a
 * branch balance. A reason note is required.
 */
class AdjustIngredientStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['nullable', 'string', 'uuid'],
            'signed_quantity' => ['required', 'numeric', 'between:-999999.999,999999.999'],
            'note' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
