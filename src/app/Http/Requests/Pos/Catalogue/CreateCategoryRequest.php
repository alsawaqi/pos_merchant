<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Models\ProductCategory;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates POST /api/categories.
 */
class CreateCategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'name_ar' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
            // Optional parent → makes this a subcategory. Company-scope +
            // 2-level cap checked in withValidator.
            'parent_id' => ['nullable', 'integer'],
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
            if ($name === '') {
                return;
            }
            $taken = ProductCategory::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A category with this name already exists.');
            }

            $this->validateParent($v, $companyId);
        });
    }

    /**
     * A parent, when supplied, must belong to this company and itself be
     * top-level (parent_id NULL) — categories nest at most one level deep.
     */
    private function validateParent(Validator $v, int $companyId): void
    {
        $parentId = $this->input('parent_id');
        if ($parentId === null || $parentId === '') {
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
        }
    }
}
