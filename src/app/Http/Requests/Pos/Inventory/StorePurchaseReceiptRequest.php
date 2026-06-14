<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PD6 — validate a Goods Received Note submit. Shape only; the controller
 * resolves the items/branches/supplier (tenant-scoped) and asserts the
 * permission + HQ scope before the action runs.
 *
 * At least one line is required (a receipt with no items is meaningless).
 * Each line carries a cost (0 allowed for a free/sample line) and an OPTIONAL
 * branch split — whatever is not split stays in the central warehouse. Charges
 * are free-form named extra costs, each booking its own categorized expense.
 */
class StorePurchaseReceiptRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_uuid' => ['nullable', 'string', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', 'string', 'in:ingredient,product'],
            'lines.*.item_uuid' => ['required', 'string', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'lines.*.line_cost' => ['required', 'numeric', 'min:0', 'max:999999.999'],
            // PT — optional tax PAID on the line (on top of line_cost).
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.allocations' => ['nullable', 'array'],
            'lines.*.allocations.*.branch_uuid' => ['required', 'string', 'uuid'],
            'lines.*.allocations.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],

            'charges' => ['nullable', 'array'],
            'charges.*.name' => ['required', 'string', 'max:120'],
            'charges.*.category' => ['required', 'string', 'in:'.implode(',', ExpenseCategory::values())],
            'charges.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'charges.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'charges.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // A line may not distribute more than it received (the action
            // enforces this too, but a field-level error reads better).
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                $distributed = 0.0;
                foreach ((array) ($line['allocations'] ?? []) as $alloc) {
                    $distributed += (float) ($alloc['quantity'] ?? 0);
                }
                if ($distributed > $qty + 1e-9) {
                    $v->errors()->add(
                        "lines.{$i}.allocations",
                        'The branch split exceeds the received quantity for this line.',
                    );
                }
            }
        });
    }
}
