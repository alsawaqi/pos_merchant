<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/branches/{branch:uuid}/stock/purchase.
 *
 * Three accepted shapes (Additions §2.4):
 *   pieces only           — fixed-ratio purchase (5 bottles of milk);
 *                           the ingredient's units_per_piece converts.
 *   pieces + units        — LOOSE batch (7 tomato pieces weighing
 *                           10 000 g); this batch's ratio becomes the
 *                           ingredient's new default.
 *   units only            — plain base-unit purchase, no piece info.
 *
 * total_paid is the money for the WHOLE batch — the unit cost is
 * derived (total ÷ units), never entered. Piece-config / fractional
 * rules are enforced in RecordPurchaseAction → 422.
 */
class RecordPurchaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ingredient_uuid' => ['required', 'string', 'uuid'],
            'pieces' => ['nullable', 'numeric', 'gt:0', 'max:999999.999'],
            // Total PRIMARY units received (the weighed amount on a
            // loose batch, or the full quantity on a plain purchase).
            'units' => ['nullable', 'numeric', 'gt:0', 'max:999999999.999'],
            'total_paid' => ['required', 'numeric', 'min:0', 'max:999999.999'],
            'supplier_uuid' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->filled('pieces') && ! $this->filled('units')) {
                $v->errors()->add('pieces', 'Enter the pieces received, the total quantity received, or both.');
            }
        });
    }
}
