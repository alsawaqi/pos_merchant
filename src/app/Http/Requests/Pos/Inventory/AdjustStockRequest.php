<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/branches/{branch:uuid}/stock/adjust.
 *
 * Note is REQUIRED — adjustments are the most audit-sensitive
 * movement type. Without a reason the ledger shows mystery
 * losses that hurt theft investigations. Action layer
 * re-enforces this defensively.
 */
class AdjustStockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The ingredient is referenced by uuid in the URL
            // path's role-bind on the controller, but for the
            // adjust endpoint we accept it in the payload so the
            // same branch endpoint serves any ingredient.
            'ingredient_uuid' => ['required', 'string', 'uuid'],
            // Signed — positive = found more, negative = found
            // less. Zero is rejected by the Action layer.
            'signed_quantity' => ['required', 'numeric', 'between:-999999.999,999999.999'],
            'note' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}
