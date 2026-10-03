<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos\Inventory\Concerns;

use App\Models\Ingredient;
use App\Support\Inventory\PackSize;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * LAUNCH item kind, A4 — an alternate unit (pack size) sent as what it HOLDS:
 * amount + a unit of the ingredient's kind ("crate holds 12 l"). The factor
 * is that amount in the ingredient's stored unit (PackSize), checked here as
 * field errors and handed to the actions in place of amount + unit.
 */
trait ResolvesPackSizeFactor
{
    protected function checkHolds(Validator $v): void
    {
        $ingredient = $this->route('ingredient');
        if (! $this->filled('amount') || ! $ingredient instanceof Ingredient || $ingredient->unit === null) {
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
     * factor they describe.
     *
     * @return array<string, mixed>
     */
    public function unitAttributes(Ingredient $ingredient): array
    {
        $attributes = $this->validated();
        if (array_key_exists('amount', $attributes) && $ingredient->unit !== null) {
            $attributes['factor'] = PackSize::factor($ingredient->unit, $attributes['amount'], (string) $attributes['unit']);
        }
        unset($attributes['amount'], $attributes['unit']);

        return $attributes;
    }
}
