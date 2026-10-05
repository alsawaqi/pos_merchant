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
            // Phase B — direct-to-branch delivery: the whole receipt lands at
            // this branch (every line auto-allocates 100% to it). Absent =
            // central warehouse, today's receive & distribute.
            'destination_branch_uuid' => ['nullable', 'string', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            // AP — was this delivery bought on credit (pay the supplier later)?
            // A due date is an optional reminder of when payment is expected.
            'is_credit' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', 'string', 'in:ingredient,product'],
            'lines.*.item_uuid' => ['required', 'string', 'uuid'],
            // LAUNCH review add-on (C1, D3) — a line bought BY CONTAINER: the
            // item's container (ingredient) or pack (physical item), how many
            // (pieces), and an optional amount that may only be LOWERED from
            // pieces × size (amount_unit = a unit of the kind; null = the
            // stored unit; for a pack the amount is the item's pieces). With
            // no container the line keeps the free quantity (loose weight).
            'lines.*.container_uuid' => ['nullable', 'string', 'max:64'],
            'lines.*.pack_uuid' => ['nullable', 'string', 'max:64'],
            'lines.*.pieces' => ['nullable', 'numeric', 'gt:0', 'max:999999.9999'],
            // Fix order B-2 — a nested container's inner count, lowered (one broken bottle).
            'lines.*.leaf_pieces' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
            'lines.*.amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999.9999'],
            'lines.*.amount_unit' => ['nullable', 'string', 'max:40'],
            'lines.*.quantity' => ['required_without:lines.*.pieces', 'nullable', 'numeric', 'gt:0', 'max:999999.999'],
            // LAUNCH-P2 P2-3 — the unit the quantity and split are entered in
            // (NULL = the base unit; kg/g, l/ml, an extra unit's name or
            // '@piece') and the price PER THAT UNIT. With a unit price the
            // server computes the line cost (quantity × price, 3dp); without
            // one the typed line cost stands, as before.
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999.999999'],
            'lines.*.line_cost' => ['required_without:lines.*.unit_price', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            // PT — optional tax PAID on the line (on top of line_cost).
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.allocations' => ['nullable', 'array'],
            'lines.*.allocations.*.branch_uuid' => ['required', 'string', 'uuid'],
            // Review add-on — a container line splits in PIECES.
            'lines.*.allocations.*.pieces' => ['nullable', 'numeric', 'gt:0', 'max:999999.9999'],
            'lines.*.allocations.*.quantity' => ['required_without:lines.*.allocations.*.pieces', 'nullable', 'numeric', 'gt:0', 'max:999999.999'],

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
            // Phase B — a direct-to-branch delivery and per-line branch
            // splits are mutually exclusive: the destination already claims
            // every line in full.
            if ($this->filled('destination_branch_uuid')) {
                foreach ((array) $this->input('lines', []) as $i => $line) {
                    if (! empty($line['allocations'])) {
                        $v->errors()->add(
                            "lines.{$i}.allocations",
                            'A direct-to-branch delivery cannot also split lines across branches — the whole receipt lands at the destination branch.',
                        );
                    }
                }
            }

            // A line may not distribute more than it received (the action
            // enforces this too, but a field-level error reads better). Both
            // are in the line's entered unit, so they compare like for like.
            // Review add-on — a container line splits in pieces.
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $byPieces = isset($line['pieces']) && $line['pieces'] !== null && $line['pieces'] !== '';
                $qty = (float) ($byPieces ? $line['pieces'] : ($line['quantity'] ?? 0));
                $distributed = 0.0;
                foreach ((array) ($line['allocations'] ?? []) as $alloc) {
                    $distributed += (float) ($byPieces ? ($alloc['pieces'] ?? 0) : ($alloc['quantity'] ?? 0));
                }
                if ($distributed > $qty + 1e-9) {
                    $v->errors()->add(
                        "lines.{$i}.allocations",
                        'The branch split exceeds the received quantity for this line.',
                    );
                }
                // C1 — pieces name a container (ingredient) or a pack (item).
                if ($byPieces && empty($line['container_uuid']) && empty($line['pack_uuid'])) {
                    $v->errors()->add("lines.{$i}.container_uuid", 'Pick the container (or pack) the pieces are in, or type the amount without a container.');
                }
                // C2 — the price paid for a container line is required (0 = free).
                if ($byPieces && (! isset($line['line_cost']) || $line['line_cost'] === null || $line['line_cost'] === '')) {
                    $v->errors()->add("lines.{$i}.line_cost", 'Enter the price paid for this line (0 for a free line).');
                }
            }
        });
    }
}
