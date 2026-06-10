<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\CategoryStatus;
use App\Models\Branch;
use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(CategoryStatus::values())],
            // Re-parent / un-nest. null promotes to top-level. Validity checked
            // in withValidator (company-scope, 2-level cap, no-self, no children).
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            // Phase D2 — §5.5.1 branch availability (all or selected).
            // Empty array / null = back to all branches. Ownership checked
            // in withValidator.
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('name')) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }
            /** @var ProductCategory|null $current */
            $current = $this->route('category');
            $currentId = $current?->id ?? 0;
            $taken = ProductCategory::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->where('id', '!=', $currentId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A category with this name already exists.');
            }
        });

        $validator->after(function (Validator $v): void {
            if (! $this->has('parent_id')) {
                return;
            }
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null) {
                return;
            }

            /** @var ProductCategory|null $current */
            $current = $this->route('category');
            $parentId = $this->input('parent_id');

            // null = promote to top-level: always allowed (even with children).
            if ($parentId === null || $parentId === '') {
                return;
            }

            if ($current !== null && (int) $parentId === (int) $current->id) {
                $v->errors()->add('parent_id', 'A category cannot be its own parent.');

                return;
            }

            $parent = ProductCategory::query()
                ->where('id', $parentId)
                ->where('company_id', $companyId)
                ->first();

            if ($parent === null) {
                $v->errors()->add('parent_id', 'The selected parent category does not belong to your company.');

                return;
            }

            if ($parent->parent_id !== null) {
                $v->errors()->add('parent_id', 'Categories can only be nested one level deep.');

                return;
            }

            // This category has its own subcategories → making it a child would
            // create a 3-level chain. Refuse.
            if ($current !== null && $current->subcategories()->exists()) {
                $v->errors()->add('parent_id', 'This category has subcategories, so it cannot become a subcategory itself.');
            }
        });

        $validator->after(function (Validator $v): void {
            $this->validateBranchIds($v);
        });
    }

    /**
     * Phase D2 — every supplied branch id must belong to this company
     * (mirrors SyncProductBranchesAction's cross-tenant guard).
     */
    private function validateBranchIds(Validator $v): void
    {
        if (! $this->has('branch_ids')) {
            return;
        }
        $companyId = app(MerchantTenantContext::class)->id();
        if ($companyId === null) {
            return;
        }

        $branchIds = $this->input('branch_ids');
        if (! is_array($branchIds) || $branchIds === []) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $branchIds)));
        $owned = Branch::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->count();
        if ($owned !== count($ids)) {
            $v->errors()->add('branch_ids', 'One or more branches do not belong to your company.');
        }
    }
}
