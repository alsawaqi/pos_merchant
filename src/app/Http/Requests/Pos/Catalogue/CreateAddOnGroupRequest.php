<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/addon-groups.
 *
 * Per-company name uniqueness checked in withValidator so the
 * merchant gets a clean 422 instead of a 500 from the DB
 * unique-index trip.
 */
class CreateAddOnGroupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'selection_mode' => ['nullable', 'string', Rule::in(AddOnSelectionMode::values())],
            // Phase B — selection constraints. min >= 1 = required group;
            // cross-field max >= min enforced in withValidator.
            'min_selections' => ['nullable', 'integer', 'between:0,99'],
            'max_selections' => ['nullable', 'integer', 'between:1,99'],
            'is_global' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
            // Phase B — category-level bindings (tenant-checked below).
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name !== '') {
                $taken = AddOnGroup::query()
                    ->where('company_id', $companyId)
                    ->where('name', $name)
                    ->exists();
                if ($taken) {
                    $v->errors()->add('name', 'An add-on group with this name already exists.');
                }
            }

            // Phase B — max must be able to satisfy min.
            $min = $this->input('min_selections');
            $max = $this->input('max_selections');
            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $v->errors()->add('max_selections', 'Maximum selections cannot be below the minimum.');
            }

            // Phase B — every bound category must belong to this company.
            $categoryIds = $this->input('category_ids');
            if (is_array($categoryIds) && $categoryIds !== []) {
                $owned = ProductCategory::query()
                    ->where('company_id', $companyId)
                    ->whereIn('id', $categoryIds)
                    ->count();
                if ($owned !== count(array_unique(array_map('intval', $categoryIds)))) {
                    $v->errors()->add('category_ids', 'One or more categories do not belong to your company.');
                }
            }
        });
    }
}
