<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
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
            'is_global' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
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
            /** @var AddOnGroup|null $current */
            $current = $this->route('addonGroup');
            $currentId = $current?->id ?? 0;
            $taken = AddOnGroup::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->where('id', '!=', $currentId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'An add-on group with this name already exists.');
            }
        });
    }
}
