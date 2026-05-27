<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateIngredientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'unit' => ['sometimes', 'string', Rule::in(IngredientUnit::values())],
            'default_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'min_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'primary_supplier_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            /** @var Ingredient|null $current */
            $current = $this->route('ingredient');
            $currentId = $current?->id ?? 0;

            if ($this->has('name')) {
                $name = trim((string) $this->input('name'));
                if ($name !== '') {
                    $taken = Ingredient::query()
                        ->where('company_id', $companyId)
                        ->where('name', $name)
                        ->where('id', '!=', $currentId)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('name', 'An ingredient with this name already exists.');
                    }
                }
            }

            if ($this->has('primary_supplier_id') && $this->filled('primary_supplier_id')) {
                $supplierOk = Supplier::query()
                    ->where('id', (int) $this->input('primary_supplier_id'))
                    ->where('company_id', $companyId)
                    ->exists();
                if (! $supplierOk) {
                    $v->errors()->add('primary_supplier_id', 'The selected supplier does not belong to your company.');
                }
            }
        });
    }
}
