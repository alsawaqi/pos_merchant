<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\ResolvesPackSizeFactor;
use App\Support\Inventory\PackSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v2 #13 — update an ingredient alternate unit. The unit's NAME is immutable
 * (renaming would orphan the recipe unit_at_set label snapshots that reference
 * it — delete + recreate instead); only the factor / Arabic label / order can
 * change. Permission gating is on the controller (inventory.manage).
 *
 * LAUNCH item kind, A4 — or what the pack HOLDS (amount + a unit of the
 * item's kind), from which the factor is worked out (PackSize).
 */
class UpdateIngredientUnitRequest extends FormRequest
{
    use ResolvesPackSizeFactor;

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
            'factor' => ['sometimes', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'unit' => ['required_with:amount', 'string', 'max:32'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkHolds($v));
    }
}
