<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\Supplier;
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
 */
class CreateIngredientRequest extends FormRequest
{
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
            'piece_unit_label' => ['nullable', 'string', 'max:32', 'required_with:units_per_piece'],
            'piece_unit_label_ar' => ['nullable', 'string', 'max:32'],
            'units_per_piece' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.9999', 'required_with:piece_unit_label'],
            'allow_fractional_pieces' => ['nullable', 'boolean'],
            'default_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'min_stock_threshold' => ['nullable', 'numeric', 'min:0', 'max:999999.999'],
            'primary_supplier_id' => ['nullable', 'integer', 'min:1'],
            // LAUNCH item kind, A3 — optional "How do you buy it?" pack sizes,
            // saved with the ingredient in the same transaction: "crate holds
            // 12 l". The amount is typed in a unit of the item's kind; the
            // factor is worked out (PackSize), never typed.
            'pack_sizes' => ['sometimes', 'array', 'max:20'],
            'pack_sizes.*' => ['array'],
            'pack_sizes.*.name' => ['required', 'string', 'max:32', 'distinct'],
            'pack_sizes.*.name_ar' => ['nullable', 'string', 'max:32'],
            'pack_sizes.*.amount' => ['required', 'numeric', 'gt:0', 'max:'.PackSize::MAX_FACTOR],
            'pack_sizes.*.unit' => ['required', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pack_sizes.*.name.required' => 'Give the pack a name (crate, sack, box).',
            'pack_sizes.*.name.distinct' => 'Two pack sizes have the same name.',
            'pack_sizes.*.amount.required' => 'Enter how much the pack holds.',
            'pack_sizes.*.amount.gt' => 'Enter how much the pack holds.',
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

            $this->checkPackSizes($v);
        });
    }

    /**
     * LAUNCH item kind, A3 — each pack size against the chosen stored unit, as
     * clean field errors: its name is not a unit of the kind, its amount is
     * in a unit of the kind and converts. CreateIngredientUnitAction applies
     * the same rules again when it saves each one.
     */
    private function checkPackSizes(Validator $v): void
    {
        $stored = IngredientUnit::tryFrom((string) $this->input('unit'));
        $packs = $this->input('pack_sizes');
        if ($stored === null || ! is_array($packs)) {
            return;
        }

        foreach ($packs as $i => $pack) {
            if (! is_array($pack)) {
                continue;
            }
            $name = is_string($pack['name'] ?? null) ? $pack['name'] : '';
            $nameProblem = $name === '' ? null : PackSize::nameProblem($stored, $name);
            if ($nameProblem !== null) {
                $v->errors()->add("pack_sizes.{$i}.name", $nameProblem);
            }

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
                PackSize::factor($stored, $amount, $unit);
            } catch (RuntimeException $e) {
                $v->errors()->add("pack_sizes.{$i}.amount", $e->getMessage());
            }
        }
    }
}
