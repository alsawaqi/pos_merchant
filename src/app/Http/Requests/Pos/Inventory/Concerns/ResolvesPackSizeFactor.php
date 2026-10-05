<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory\Concerns;

use App\Models\Ingredient;
use App\Support\Inventory\Containers;
use App\Support\Inventory\PackSize;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * LAUNCH item kind, A4 — an alternate unit (pack size) sent as what it HOLDS:
 * amount + a unit of the ingredient's kind ("crate holds 12 l"). The factor
 * is that amount in the ingredient's stored unit (PackSize), checked here as
 * field errors and handed to the actions in place of amount + unit.
 *
 * LAUNCH review add-on (A2) — or what it holds as N × another container of
 * the same item: contains_unit_uuid + contains_quantity ("crate holds 12 ×
 * bottle 1 l"), resolved within the item only.
 */
trait ResolvesPackSizeFactor
{
    protected function checkHolds(Validator $v): void
    {
        $ingredient = $this->route('ingredient');
        if (! $ingredient instanceof Ingredient || $ingredient->unit === null) {
            return;
        }

        if ($this->filled('contains_unit_uuid')) {
            if (Containers::findByUuid($ingredient, (string) $this->input('contains_unit_uuid')) === null) {
                $v->errors()->add('contains_unit_uuid', 'A container can only hold another container of the same item.');
            }

            return;
        }

        if (! $this->filled('amount')) {
            return;
        }
        $unit = $this->input('unit');
        $amount = $this->input('amount');
        if (! is_string($unit) || $unit === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            return;
        }

        $unitProblem = PackSize::unitProblem($ingredient->unit, $unit);
        if ($unitProblem !== null) {
            $v->errors()->add('unit', $unitProblem);

            return;
        }
        try {
            PackSize::factor($ingredient->unit, $amount, $unit);
        } catch (RuntimeException $e) {
            $v->errors()->add('amount', $e->getMessage());
        }
    }

    /**
     * The validated attributes for the unit actions: amount + unit become the
     * factor they describe; contains_unit_uuid becomes the content's id.
     *
     * @return array<string, mixed>
     */
    public function unitAttributes(Ingredient $ingredient): array
    {
        $attributes = $this->validated();
        if (array_key_exists('amount', $attributes) && $attributes['amount'] !== null && $ingredient->unit !== null) {
            $attributes['factor'] = PackSize::factor($ingredient->unit, $attributes['amount'], (string) $attributes['unit']);
            $attributes['contains_unit_id'] = null;
            $attributes['contains_quantity'] = null;
        }
        if (array_key_exists('contains_unit_uuid', $attributes) && $attributes['contains_unit_uuid'] !== null && $attributes['contains_unit_uuid'] !== '') {
            $content = Containers::findByUuid($ingredient, (string) $attributes['contains_unit_uuid']);
            $attributes['contains_unit_id'] = $content?->id;
            unset($attributes['factor']);
        }
        unset($attributes['amount'], $attributes['unit'], $attributes['contains_unit_uuid'], $attributes['barcodes']);

        return $attributes;
    }
}
