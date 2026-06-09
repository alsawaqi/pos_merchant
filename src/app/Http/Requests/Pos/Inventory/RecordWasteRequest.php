<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\WasteReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/branches/{branch:uuid}/waste.
 *
 * The branch is route-bound and tenant-checked by the controller.
 * Ingredient resolution + the cross-tenant check happen in the
 * Action layer (single source of truth for tenant validation).
 *
 * Quantity is positive — the action signs it negative for the
 * matching stock movement. Reason must be in the WasteReason
 * enum; 'other' enforces non-empty notes via the action (we
 * can't do it cleanly in rules without a custom Rule object
 * because Laravel's required_if only fires on equality of
 * the OTHER field's value, which the validator messages around
 * confusingly).
 */
class RecordWasteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ingredient_uuid' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            // #13 — entered unit (alt-unit name, or null = base); converted to
            // base before write. Validity enforced by IngredientUnitConverter → 422.
            'unit' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', Rule::in(WasteReason::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Optional retroactive timestamp — defaults to now
            // when omitted. Bounded to a reasonable past window
            // (90 days) to prevent obviously-wrong entries.
            'occurred_at' => ['nullable', 'date', 'after_or_equal:-90 days', 'before_or_equal:+1 day'],
        ];
    }
}
