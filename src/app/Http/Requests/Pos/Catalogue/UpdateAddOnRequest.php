<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Support\Catalogue\AddOnKindRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAddOnRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:100'],
            'price_delta' => ['sometimes', 'numeric', 'min:0', 'max:999.999'],
            'is_default' => ['sometimes', 'boolean'],
            // P-G3 — link/unlink the real product behind this option
            // (null = back to a classic label-only add-on).
            'linked_product_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
            'display_order' => ['sometimes', 'integer', 'between:0,999'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            // PD3b — key present (even []) replaces the stock-usage
            // lines; absent leaves them untouched.
        ] + CreateAddOnRequest::consumptionRules();
    }

    /**
     * LAUNCH review add-on — an option of a Quick instructions group is free,
     * uses no stock and sells no linked product.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            $addon = $this->route('addon');
            $group = $addon instanceof AddOn ? AddOnGroup::query()->withTrashed()->find($addon->add_on_group_id) : null;
            AddOnKindRules::checkOption($v, $group, $this->all());
        }];
    }
}
