<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/branches/{branch:uuid}/stock/restock.
 *
 * Note here is OPTIONAL — restocks are routine purchases and
 * the merchant shouldn't be forced to justify every milk
 * delivery. Adjustment (the corrections path) keeps the
 * required-note guard.
 *
 * Supplier reference is by uuid (matches the picker UX in
 * the UI). Tenant check happens in the Action.
 */
class RestockRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ingredient_uuid' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            // NULL means "use ingredient.default_unit_cost".
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'supplier_uuid' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
