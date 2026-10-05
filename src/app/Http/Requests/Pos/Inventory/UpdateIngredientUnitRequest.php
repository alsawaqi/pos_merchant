<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Http\Requests\Pos\Inventory\Concerns\ResolvesPackSizeFactor;
use App\Support\Inventory\PackSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v2 #13 — update an ingredient alternate unit. Permission gating is on the
 * controller (inventory.manage).
 *
 * LAUNCH review add-on (A2, tester call 5) — the name and Arabic name stay
 * editable (recipes now name a container by its token); the SIZE is locked
 * once the container is used (UpdateIngredientUnitAction refuses it).
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
            // LAUNCH review add-on (A2, tester call 5) — the name stays editable.
            'name' => ['sometimes', 'string', 'max:32'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            'factor' => ['sometimes', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'unit' => ['required_with:amount', 'string', 'max:32'],
            'contains_unit_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contains_quantity' => ['required_with:contains_unit_uuid', 'nullable', 'integer', 'min:2', 'max:'.PackSize::MAX_FACTOR],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkHolds($v));
    }
}
