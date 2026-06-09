<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v2 #13 — update an ingredient alternate unit. The unit's NAME is immutable
 * (renaming would orphan the recipe unit_at_set label snapshots that reference
 * it — delete + recreate instead); only the factor / Arabic label / order can
 * change. Permission gating is on the controller (inventory.manage).
 */
class UpdateIngredientUnitRequest extends FormRequest
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
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            'factor' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
