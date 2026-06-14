<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use App\Enums\StaffPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P-G1 (revised) — validate the device Kitchen-section access policy.
 *
 * The list is OPTIONAL: an empty array is valid and means "only the kitchen
 * role" (the kitchen role ALWAYS has access — enforced in pos_api — so it is
 * never a selectable choice here; submitting it is rejected). Each entry must
 * be a known non-kitchen StaffPosition. Permission gating lives in the
 * controller (orders.cancel, the sibling position policies' gate).
 */
class UpdateKitchenPositionsRequest extends FormRequest
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
            // present (not required) — the key must be sent but may be empty [].
            'positions' => ['present', 'array'],
            // The kitchen role is implicit + always allowed, so it is NOT a valid
            // explicit choice; only the other positions can be ticked.
            'positions.*' => ['string', Rule::in(self::selectablePositions())],
        ];
    }

    /**
     * The positions a merchant may tick for kitchen access — everything except
     * the always-implicit 'kitchen' role.
     *
     * @return list<string>
     */
    public static function selectablePositions(): array
    {
        return array_values(array_filter(
            StaffPosition::values(),
            static fn (string $p): bool => $p !== StaffPosition::Kitchen->value,
        ));
    }
}
