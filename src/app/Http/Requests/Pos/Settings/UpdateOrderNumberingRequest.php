<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * P-F8 — validate the order numbering policy shape:
 * {enabled: bool, prefix: string(<=8, may be ''), pad: int 3..6,
 *  scope: 'branch'|'company', daily_reset: bool}.
 *
 * `prefix` must be PRESENT but may be empty — the global
 * ConvertEmptyStringsToNull middleware turns '' into null, so it is
 * nullable here and the action persists null as ''. Permission gating
 * lives in the controller (orders.cancel, same gate as the sibling POS
 * policy settings).
 */
class UpdateOrderNumberingRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'prefix' => ['present', 'nullable', 'string', 'max:8'],
            'pad' => ['required', 'integer', 'min:3', 'max:6'],
            'scope' => ['required', 'string', Rule::in(['branch', 'company'])],
            'daily_reset' => ['required', 'boolean'],
        ];
    }
}
