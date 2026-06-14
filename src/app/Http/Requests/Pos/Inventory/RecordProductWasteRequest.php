<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\WasteReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate recording wastage of a product at a branch. Shape only — the action
 * enforces the tenant/stock-mode/sufficient-stock rules and the 'other requires
 * notes' rule.
 */
class RecordProductWasteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_uuid' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'reason' => ['required', 'string', Rule::in(WasteReason::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
