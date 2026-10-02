<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Catalogue;

use App\Actions\Pos\Catalogue\SavePrepItemAction;
use App\Models\Ingredient;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * LAUNCH-P3 P3-4 — validates POST /api/prep-items and PATCH /api/prep-items/{uuid}.
 *
 * A prep item is a name, a SMALL base unit (g, ml or piece — "buy big, use
 * small"), a yield (what one batch makes, in that unit) and a recipe of
 * ingredients or other prep items, each line typed with the P3-1 unit picker.
 * Quantities keep at most 4 decimals (the ledger's precision) so the editor
 * reopens exactly what was typed.
 *
 * Tenant ownership, same-company components, the 3-level limit, cycles and
 * amounts that would round to 0 are checked in {@see SavePrepItemAction}
 * (RuntimeException → 422) — they need the company's whole prep graph.
 */
class SavePrepItemRequest extends FormRequest
{
    /** The small base units a prep item may use. */
    public const UNITS = ['g', 'ml', 'piece'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'unit' => [$required, 'string', 'in:'.implode(',', self::UNITS)],
            'prep_yield_quantity' => [$required, 'numeric', 'gt:0', 'decimal:0,4', 'max:9999999.9999'],
            'lines' => [$required, 'array', 'min:1', 'max:50'],
            'lines.*.ingredient_uuid' => ['required', 'string', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999.999'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'A prep item needs at least one ingredient in its recipe.',
            'lines.required' => 'A prep item needs at least one ingredient in its recipe.',
            'prep_yield_quantity.gt' => 'The yield (what one batch makes) must be more than 0.',
            'lines.*.quantity.decimal' => 'Use at most 4 decimal places.',
            'prep_yield_quantity.decimal' => 'Use at most 4 decimal places.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $companyId = app(MerchantTenantContext::class)->id();
            if ($companyId === null || ! $this->filled('name')) {
                return;
            }

            // Prep items share the ingredients' name space (one table, a
            // unique (company, name) index that also covers deleted rows).
            $current = $this->route('prepItem');
            $taken = Ingredient::query()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->where('name', trim((string) $this->input('name')))
                ->when($current instanceof Ingredient, fn ($q) => $q->where('id', '!=', $current->id))
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'An ingredient or prep item with this name already exists (it may be a deleted one).');
            }
        });
    }
}
