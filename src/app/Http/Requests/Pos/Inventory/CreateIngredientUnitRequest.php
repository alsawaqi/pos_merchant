<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v2 #13 — create an ingredient alternate unit. Shape only; the contextual
 * checks (name ≠ base unit, unique within the ingredient) live in
 * {@see \App\Actions\Pos\Inventory\CreateIngredientUnitAction} so they return a
 * clean 422 message. Permission gating is on the controller (inventory.manage).
 */
class CreateIngredientUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:32'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            // base units per ONE of this unit — a positive amount, capped so a
            // huge factor can't overflow the decimal(12,3) base-stock columns.
            'factor' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
