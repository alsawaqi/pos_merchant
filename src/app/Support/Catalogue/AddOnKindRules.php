<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOn;
use App\Models\AddOnConsumption;
use App\Models\AddOnGroup;
use Illuminate\Validation\Validator;

/**
 * LAUNCH review add-on (D12, tester call 1) — what each add-on group kind
 * allows (menu audit §7.2):
 *
 *   - extras: unchanged (priced options, stock usage, linked products);
 *   - instructions ("Quick instructions": Well done, Less spicy, Sauce on the
 *     side): options cost nothing, use no stock and sell no linked product;
 *     the group is several-choice and never required. Bound per product or
 *     per category with today's pivots;
 *   - remove: only through the recipe step ({@see RemovableIngredients}).
 */
final class AddOnKindRules
{
    /** The kinds the add-on endpoints may create or switch to. */
    public const EDITABLE_KINDS = [AddOnGroup::KIND_EXTRAS, AddOnGroup::KIND_INSTRUCTIONS];

    public const NOT_FREE = 'A quick instruction has no price: leave it at 0.';

    public const NO_LINK = 'A quick instruction sells no product: remove the linked product.';

    public const NO_STOCK = 'A quick instruction uses no stock: remove its stock lines.';

    /** Quick instructions: several-choice, never required (what is sent). */
    public static function checkGroupShape(Validator $v, string $kind, mixed $selectionMode, mixed $minSelections): void
    {
        if ($kind !== AddOnGroup::KIND_INSTRUCTIONS) {
            return;
        }
        if ($selectionMode !== null && $selectionMode !== '' && (string) $selectionMode !== AddOnSelectionMode::Multi->value) {
            $v->errors()->add('selection_mode', 'Quick instructions let the customer pick several: choose "multiple".');
        }
        if ($minSelections !== null && $minSelections !== '' && (int) $minSelections > 0) {
            $v->errors()->add('min_selections', 'Quick instructions are never required: leave the minimum empty.');
        }
    }

    /** A group switched to Quick instructions must hold only free, stockless options. */
    public static function checkOptionsFitInstructions(Validator $v, AddOnGroup $group): void
    {
        $options = AddOn::query()->where('add_on_group_id', $group->id)->get();
        $priced = $options->contains(fn (AddOn $o): bool => (float) $o->price_delta != 0.0);
        $linked = $options->contains(fn (AddOn $o): bool => $o->linked_product_id !== null);
        $stock = $options->isNotEmpty()
            && AddOnConsumption::query()->whereIn('add_on_id', $options->pluck('id')->all())->exists();
        if ($priced || $linked || $stock) {
            $v->errors()->add('kind', 'Quick instructions have no price, stock or linked product: change or remove those options first.');
        }
    }

    /**
     * An option of a Quick instructions group (create, or the fields an
     * update sends).
     *
     * @param  array<string, mixed>  $input
     */
    public static function checkOption(Validator $v, ?AddOnGroup $group, array $input): void
    {
        if ($group === null || ! $group->isInstructionsGroup()) {
            return;
        }
        if (array_key_exists('price_delta', $input) && $input['price_delta'] !== null && $input['price_delta'] !== ''
            && is_numeric($input['price_delta']) && (float) $input['price_delta'] != 0.0) {
            $v->errors()->add('price_delta', self::NOT_FREE);
        }
        if (! empty($input['linked_product_uuid'])) {
            $v->errors()->add('linked_product_uuid', self::NO_LINK);
        }
        if (! empty($input['consumption'])) {
            $v->errors()->add('consumption', self::NO_STOCK);
        }
    }
}
