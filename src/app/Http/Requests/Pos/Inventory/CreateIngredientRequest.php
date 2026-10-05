<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Support\Inventory\ItemCodes;
use App\Support\Inventory\PackSize;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * Validates POST /api/ingredients.
 *
 * Per-company name uniqueness + tenant ownership check on
 * supplier reference. Bare validation rules cover scalar
 * shape; withValidator handles relational integrity so the
 * merchant gets clean 422s instead of 500s on DB unique trip
 * or RuntimeException bubble.
 *
 * LAUNCH review add-on:
 *   A1 no cost field — the cost comes only from purchases (the weighted
 *      average); a cost other than 0 is refused with a clear message.
 *   A2 containers ("How do you buy it?", still sent as pack_sizes[]): the
 *      same word with different sizes, and nested — "crate holds 12 ×
 *      bottle 1 l" = contains_index (an EARLIER row) + contains_quantity.
 *   A3 one row may be marked count_container ("Tills count in this").
 *   A4 sku — the supplier's code, or blank = generated (ING-0001).
 *   A5 barcodes per container, and on the item itself.
 */
class CreateIngredientRequest extends FormRequest
{
    public const COST_MESSAGE = 'An ingredient\'s cost now comes only from its purchases (the weighted average). Leave the cost out — it shows "No cost yet" until the first priced purchase.';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'unit' => ['required', 'string', Rule::in(IngredientUnit::values())],
            // Phase A — piece model (Additions §2.3). Label and ratio come as a
            // pair: a label without a ratio can't convert, a ratio without a
            // label can't be rendered. required_with enforces both-or-neither.
            // Review add-on A3: the portal marks a container instead; a pair
            // sent here becomes the count container row.
            'piece_unit_label' => ['nullable', 'string', 'max:32', 'required_with:units_per_piece'],
            'piece_unit_label_ar' => ['nullable', 'string', 'max:32'],
            'units_per_piece' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.9999', 'required_with:piece_unit_label'],
            'allow_fractional_pieces' => ['nullable', 'boolean'],
            'default_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'min_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'primary_supplier_id' => ['nullable', 'integer', 'min:1'],
            'sku' => ['nullable', 'string', 'max:64'],
            'barcodes' => ['sometimes', 'array', 'max:20'],
            'barcodes.*' => ['string', 'max:64'],
            // LAUNCH item kind, A3 — optional "How do you buy it?" pack sizes,
            // saved with the ingredient in the same transaction: "crate holds
            // 12 l". The amount is typed in a unit of the item's kind; the
            // factor is worked out (PackSize), never typed.
            'pack_sizes' => ['sometimes', 'array', 'max:20'],
            'pack_sizes.*' => ['array'],
            'pack_sizes.*.name' => ['required', 'string', 'max:32'],
            'pack_sizes.*.name_ar' => ['nullable', 'string', 'max:32'],
            'pack_sizes.*.amount' => ['nullable', 'required_without:pack_sizes.*.contains_index', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'pack_sizes.*.unit' => ['nullable', 'required_with:pack_sizes.*.amount', 'string', 'max:32'],
            'pack_sizes.*.contains_index' => ['nullable', 'integer', 'min:0'],
            'pack_sizes.*.contains_quantity' => ['nullable', 'required_with:pack_sizes.*.contains_index', 'integer', 'min:2', 'max:'.PackSize::MAX_FACTOR],
            'pack_sizes.*.count_container' => ['nullable', 'boolean'],
            'pack_sizes.*.barcodes' => ['sometimes', 'array', 'max:20'],
            'pack_sizes.*.barcodes.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pack_sizes.*.name.required' => 'Give the container a name (bottle, crate, sack, box).',
            'pack_sizes.*.amount.required_without' => 'Enter how much the container holds.',
            'pack_sizes.*.amount.gt' => 'Enter how much the container holds.',
            'pack_sizes.*.contains_quantity.min' => 'A container holds at least 2 of another container.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            // Name uniqueness within the company.
            $name = trim((string) $this->input('name'));
            if ($name !== '') {
                $taken = Ingredient::query()
                    ->where('company_id', $companyId)
                    ->where('name', $name)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('name', 'An ingredient with this name already exists.');
                }
            }

            // Supplier tenant check.
            if ($this->filled('primary_supplier_id')) {
                $supplierOk = Supplier::query()
                    ->where('id', (int) $this->input('primary_supplier_id'))
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $supplierOk) {
                    $v->errors()->add('primary_supplier_id', 'The selected supplier does not belong to your company.');
                }
            }

            // Review add-on A1 — no cost on create (0 / blank still pass: an
            // old portal tab sends "0.000").
            if ($this->filled('default_unit_cost') && is_numeric($this->input('default_unit_cost')) && (float) $this->input('default_unit_cost') != 0.0) {
                $v->errors()->add('default_unit_cost', self::COST_MESSAGE);
            }

            // A4 — a typed SKU is unique across ingredients and products.
            $sku = ItemCodes::normalize($this->input('sku'));
            if ($sku !== '' && ($owner = ItemCodes::skuOwner($companyId, $sku)) !== null) {
                $v->errors()->add('sku', ItemCodes::skuMessage($owner));
            }

            $this->checkPackSizes($v);
            $this->checkBarcodes($v, $companyId);
        });
    }

    /**
     * LAUNCH item kind, A3 — each pack size against the chosen stored unit, as
     * clean field errors: its name is not a unit of the kind, its amount is
     * in a unit of the kind and converts. CreateIngredientUnitAction applies
     * the same rules again when it saves each one.
     *
     * Review add-on A2/A3 — a nested row holds an EARLIER row; at most one row
     * is the count container; the same name + size twice is refused.
     */
    private function checkPackSizes(Validator $v): void
    {
        $stored = IngredientUnit::tryFrom((string) $this->input('unit'));
        $packs = $this->input('pack_sizes');
        if ($stored === null || ! is_array($packs)) {
            return;
        }

        $factors = [];
        $countContainers = 0;
        $seen = [];
        foreach (array_values($packs) as $i => $pack) {
            if (! is_array($pack)) {
                continue;
            }
            $name = is_string($pack['name'] ?? null) ? $pack['name'] : '';
            $nameProblem = $name === '' ? null : PackSize::nameProblem($stored, $name);
            if ($nameProblem !== null) {
                $v->errors()->add("pack_sizes.{$i}.name", $nameProblem);
            }
            if (! empty($pack['count_container'])) {
                $countContainers++;
            }

            $factor = null;
            if (isset($pack['contains_index']) && $pack['contains_index'] !== null && $pack['contains_index'] !== '') {
                $child = (int) $pack['contains_index'];
                if ($child >= $i || ! isset($factors[$child])) {
                    $v->errors()->add("pack_sizes.{$i}.contains_index", 'A container can hold only a container listed above it.');

                    continue;
                }
                $quantity = $pack['contains_quantity'] ?? null;
                if (! is_numeric($quantity) || (int) $quantity < 2) {
                    continue;
                }
                $factor = (float) $factors[$child] * (int) $quantity;
                if ($factor > PackSize::MAX_FACTOR) {
                    $v->errors()->add("pack_sizes.{$i}.contains_quantity", sprintf('A container can hold at most %s %s.', number_format(PackSize::MAX_FACTOR), $stored->value));

                    continue;
                }
                $factor = number_format($factor, 4, '.', '');
            } else {
                $unit = $pack['unit'] ?? null;
                if (! is_string($unit) || $unit === '') {
                    continue;
                }
                $unitProblem = PackSize::unitProblem($stored, $unit);
                if ($unitProblem !== null) {
                    $v->errors()->add("pack_sizes.{$i}.unit", $unitProblem);

                    continue;
                }
                $amount = $pack['amount'] ?? null;
                if (! is_numeric($amount) || (float) $amount <= 0 || (float) $amount > PackSize::MAX_FACTOR) {
                    continue;
                }
                try {
                    $factor = PackSize::factor($stored, $amount, $unit);
                } catch (RuntimeException $e) {
                    $v->errors()->add("pack_sizes.{$i}.amount", $e->getMessage());

                    continue;
                }
            }
            $factors[$i] = $factor;
            $key = mb_strtolower(trim($name)).'|'.$factor.'|'.($pack['contains_index'] ?? '');
            if (isset($seen[$key])) {
                $v->errors()->add("pack_sizes.{$i}.name", 'Two containers have the same name and size.');
            }
            $seen[$key] = true;
        }
        if ($countContainers > 1) {
            $v->errors()->add('pack_sizes', 'Only one container can be the one tills count in.');
        }
    }

    /** A5 — every barcode typed on the form is unique (in the form and in the company). */
    private function checkBarcodes(Validator $v, int $companyId): void
    {
        $seen = [];
        $check = function (mixed $code, string $field) use ($v, $companyId, &$seen): void {
            $barcode = ItemCodes::normalize($code);
            if ($barcode === '') {
                return;
            }
            if (isset($seen[mb_strtolower($barcode)])) {
                $v->errors()->add($field, 'The same barcode is entered twice.');

                return;
            }
            $seen[mb_strtolower($barcode)] = true;
            if (($owner = ItemCodes::barcodeOwner($companyId, $barcode)) !== null) {
                $v->errors()->add($field, ItemCodes::barcodeMessage($owner));
            }
        };
        foreach ((array) $this->input('barcodes', []) as $j => $code) {
            $check($code, "barcodes.{$j}");
        }
        foreach (array_values((array) $this->input('pack_sizes', [])) as $i => $pack) {
            foreach ((array) (is_array($pack) ? ($pack['barcodes'] ?? []) : []) as $j => $code) {
                $check($code, "pack_sizes.{$i}.barcodes.{$j}");
            }
        }
    }
}
