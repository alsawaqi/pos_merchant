<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Actions\Pos\Inventory\CreateIngredientUnitAction;
use App\Http\Requests\Pos\Inventory\Concerns\ResolvesPackSizeFactor;
use App\Support\Inventory\PackSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * v2 #13 — create an ingredient alternate unit. Shape only; the contextual
 * checks (name ≠ base unit, unique within the ingredient) live in
 * {@see CreateIngredientUnitAction} so they return a
 * clean 422 message. Permission gating is on the controller (inventory.manage).
 *
 * LAUNCH item kind, A4 — the portal's Pack sizes section sends what the pack
 * HOLDS (amount + a unit of the item's kind, "crate holds 12 l") instead of a
 * factor; the factor is worked out from it (PackSize). A raw factor is still
 * accepted (API compatibility).
 */
class CreateIngredientUnitRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:32'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            // base units per ONE of this unit — a positive amount, capped so a
            // huge factor can't overflow the decimal(12,3) base-stock columns.
            'factor' => ['required_without:amount', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'amount' => ['required_without:factor', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'unit' => ['required_with:amount', 'string', 'max:32'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkHolds($v));
    }
}
