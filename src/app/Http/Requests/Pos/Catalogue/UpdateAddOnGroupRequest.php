<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\ProductCategory;
use App\Support\Catalogue\AddOnKindRules;
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
            // LAUNCH review add-on — Extras or Quick instructions.
            'kind' => ['sometimes', 'string', Rule::in(AddOnKindRules::EDITABLE_KINDS)],
        ];
    }

    /**
     * LAUNCH review add-on — the kind against the merged (PATCH) state: a
     * product's own group stays Extras; Quick instructions are several-choice,
     * never required, and every option has price 0, no stock and no linked
     * product (a group turned into Quick instructions must already be so).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $v): void {
            /** @var AddOnGroup|null $current */
            $current = $this->route('addonGroup');
            if ($current === null) {
                return;
            }
            $kind = $this->has('kind') ? (string) $this->input('kind') : $current->kindValue();
            if ($this->has('kind') && $kind !== $current->kindValue() && $current->owner_product_id !== null) {
                $v->errors()->add('kind', 'A product\'s own add-on group is an Extras group.');
            }
            // Only what is sent is checked: the action makes a group that
            // becomes Quick instructions several-choice and not required.
            AddOnKindRules::checkGroupShape(
                $v,
                $kind,
                $this->has('selection_mode') ? $this->input('selection_mode') : null,
                $this->has('min_selections') ? $this->input('min_selections') : null,
            );
            if ($kind === AddOnGroup::KIND_INSTRUCTIONS && ! $current->isInstructionsGroup()) {
                AddOnKindRules::checkOptionsFitInstructions($v, $current);
            }
        }];
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
                    // LAUNCH-P4 M4 — a product's own group is unique per
                    // product, a shared group per company among shared
                    // groups; soft-deleted groups still hold their name
                    // (the DB indexes have no deleted_at filter).
                    $ownerId = $current?->owner_product_id !== null ? (int) $current->owner_product_id : null;
                    if (AddOnGroup::nameTaken((int) $companyId, $ownerId, $name, $current?->id)) {
                        $v->errors()->add('name', $ownerId !== null
                            ? 'This product already has an add-on group with this name (it may belong to a deleted group).'
                            : 'A shared add-on group with this name already exists (it may belong to a deleted group).');
                    }
                }
            }

            // Phase B — max >= min against the merged (PATCH) state.
            $min = $this->has('min_selections') ? $this->input('min_selections') : $current?->min_selections;
            $max = $this->has('max_selections') ? $this->input('max_selections') : $current?->max_selections;
            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $v->errors()->add('max_selections', 'Maximum selections cannot be below the minimum.');
            }

            // A SINGLE-choice group holds at most one selection on the POS,
            // so a minimum above 1 can never be satisfied — the customize
            // sheet's Apply button would be permanently disabled. Checked
            // against the merged state so both "raise min on a single group"
            // and "flip a min>1 group to single" are caught.
            $mode = $this->has('selection_mode')
                ? (string) $this->input('selection_mode')
                : ($current?->selection_mode?->value ?? AddOnSelectionMode::Single->value);
            if ($mode === AddOnSelectionMode::Single->value && $min !== null && (int) $min > 1) {
                $v->errors()->add('min_selections', 'A single-choice group can require at most one selection.');
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
