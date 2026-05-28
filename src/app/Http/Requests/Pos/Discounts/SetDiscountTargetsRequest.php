<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Discounts;

use App\Enums\DiscountTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /api/discounts/{discount:uuid}/targets.
 *
 * Body shape:
 *   { "targets": [
 *       {"target_type": "product",  "target_id": 42},
 *       {"target_type": "category", "target_id": 7}
 *     ] }
 *
 * Empty array is allowed at this layer (the Action enforces
 * the order-scope "no targets" rule semantically).
 */
class SetDiscountTargetsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'targets' => ['present', 'array', 'max:200'],
            'targets.*.target_type' => ['required', 'string', Rule::in(DiscountTargetType::values())],
            'targets.*.target_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
