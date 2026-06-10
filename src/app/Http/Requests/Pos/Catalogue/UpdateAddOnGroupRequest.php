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

class UpdateAddOnGroupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'selection_mode' => ['sometimes', 'string', Rule::in(AddOnSelectionMode::values())],
            // Phase B — selection constraints. min >= 1 = required group;
            // cross-field max >= min (against the EFFECTIVE merged state)
            // enforced in withValidator.
            'min_selections' => ['sometimes', 'nullable', 'integer', 'between:0,99'],
            'max_selections' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'is_global' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            // Phase B — full-list category binding sync.
            'category_ids' => ['sometimes', 'array', 'max:100'],
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
            /** @var AddOnGroup|null $current */
            $current = $this->route('addonGroup');

            if ($this->has('name')) {
                $name = trim((string) $this->input('name'));
                if ($name !== '') {
                    $taken = AddOnGroup::query()
                        ->where('company_id', $companyId)
                        ->where('name', $name)
                        ->where('id', '!=', $current?->id ?? 0)
                        ->exists();
                    if ($taken) {
                        $v->errors()->add('name', 'An add-on group with this name already exists.');
                    }
                }
            }

            // Phase B — max >= min against the merged (PATCH) state.
            $min = $this->has('min_selections') ? $this->input('min_selections') : $current?->min_selections;
            $max = $this->has('max_selections') ? $this->input('max_selections') : $current?->max_selections;
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
