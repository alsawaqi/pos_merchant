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
            // #13 — the unit the quantity was entered in (an alt-unit name, or
            // null/absent = the ingredient's base unit). Validity (must be base
            // or a defined alt unit) is enforced by IngredientUnitConverter → 422.
            'unit' => ['nullable', 'string', 'max:32'],
            // NULL means "use ingredient.default_unit_cost".
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'supplier_uuid' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
