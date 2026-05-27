<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
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
            'is_global' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer', 'between:0,999'],
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
            $taken = AddOnGroup::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'An add-on group with this name already exists.');
            }
        });
    }
}
