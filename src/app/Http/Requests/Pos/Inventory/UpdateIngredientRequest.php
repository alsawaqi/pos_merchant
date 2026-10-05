<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Support\Inventory\Containers;
use App\Support\Inventory\ItemCodes;
use App\Support\MerchantTenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/ingredients/{uuid}.
 *
 * LAUNCH review add-on:
 *   A1 the cost is read-only (it comes from purchases): a CHANGED
 *      default_unit_cost is refused; the unchanged value an old portal tab
 *      sends back still passes.
 *   A3 the count container is a container row (count_container_uuid; '' or
 *      null = none). Once the item has one, the piece_* / units_per_piece
 *      mirror changes only through it: a changed value sent here is refused.
 *   A4 sku — unique across ingredients and products (case-insensitive); a
 *      blank keeps the current code, or generates one when there is none.
 */
class UpdateIngredientRequest extends FormRequest
{
    public const COST_MESSAGE = 'The cost cannot be typed any more: it comes from purchases (the weighted average). Record a purchase to change it.';

    public const PIECE_MESSAGE = 'The count container is now one of the item\'s containers: mark it "Tills count in this" in the containers list.';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'unit' => ['sometimes', 'string', Rule::in(IngredientUnit::values())],
            // Phase A — piece model. The both-or-neither pairing against the
            // EFFECTIVE (merged) state is enforced in withValidator below,
            // because a partial PATCH may legitimately send only one of them.
            'piece_unit_label' => ['sometimes', 'nullable', 'string', 'max:32'],
            'piece_unit_label_ar' => ['sometimes', 'nullable', 'string', 'max:32'],
            'units_per_piece' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'allow_fractional_pieces' => ['sometimes', 'boolean'],
            'default_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'min_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.999'],
            'primary_supplier_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'count_container_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
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

            // Review add-on A1 — the cost is read-only: refuse a CHANGE only.
            if ($current instanceof Ingredient && $this->has('default_unit_cost') && is_numeric($this->input('default_unit_cost'))) {
                $sent = BigDecimal::of(trim((string) $this->input('default_unit_cost')))->toScale(6, RoundingMode::HALF_UP);
                $stored = BigDecimal::of(self::text($current->getRawOriginal('default_unit_cost')))->toScale(6, RoundingMode::HALF_UP);
                if (! $sent->isEqualTo($stored)) {
                    $v->errors()->add('default_unit_cost', self::COST_MESSAGE);
                }
            }

            // A4 — the SKU is unique across ingredients and products.
            $sku = ItemCodes::normalize($this->input('sku'));
            if ($this->has('sku') && $sku !== '' && ($owner = ItemCodes::skuOwner($companyId, $sku, (int) $currentId)) !== null) {
                $v->errors()->add('sku', ItemCodes::skuMessage($owner));
            }

            // A3 — the count container is one of THIS item's live containers.
            if ($current instanceof Ingredient && $this->filled('count_container_uuid')
                && Containers::findByUuid($current, (string) $this->input('count_container_uuid')) === null) {
                $v->errors()->add('count_container_uuid', 'The count container must be one of this item\'s containers.');
            }

            // A3 — once a count container exists, the mirror follows it only.
            if ($current instanceof Ingredient && $current->count_container_id !== null && ! $this->has('count_container_uuid')) {
                foreach (['piece_unit_label', 'piece_unit_label_ar', 'units_per_piece'] as $field) {
                    if (! $this->has($field)) {
                        continue;
                    }
                    $sent = $this->input($field);
                    $now = $current->{$field};
                    $same = $field === 'units_per_piece'
                        ? (($sent === null || $sent === '') === ($now === null)) && ($now === null || abs((float) $sent - (float) $now) < 1e-9)
                        : trim((string) ($sent ?? '')) === trim((string) ($now ?? ''));
                    if (! $same) {
                        $v->errors()->add($field, self::PIECE_MESSAGE);
                    }
                }
            }

            // Phase A — both-or-neither on the EFFECTIVE piece config (the
            // ingredient's current value merged with whatever this PATCH sends).
            if ($this->has('piece_unit_label') || $this->has('units_per_piece')) {
                /** @var Ingredient|null $ingredient */
                $ingredient = $this->route('ingredient');
                $label = $this->has('piece_unit_label')
                    ? $this->input('piece_unit_label')
                    : $ingredient?->piece_unit_label;
                $ratio = $this->has('units_per_piece')
                    ? $this->input('units_per_piece')
                    : $ingredient?->units_per_piece;
                if (($label === null || $label === '') !== ($ratio === null || $ratio === '')) {
                    $v->errors()->add(
                        $this->has('piece_unit_label') ? 'piece_unit_label' : 'units_per_piece',
                        'Piece unit label and units-per-piece must be set together (or both cleared).',
                    );
                }
            }
        });
    }

    private static function text(mixed $value): string
    {
        if (is_float($value)) {
            return number_format($value, 8, '.', '');
        }

        return trim((string) $value) === '' ? '0' : trim((string) $value);
    }
}
