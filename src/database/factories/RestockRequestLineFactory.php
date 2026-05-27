<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockRequestLine>
 *
 * Default: a 5.000 kg line with zero allocated. Caller MUST
 * provide ->for($restockRequest, 'request') and
 * ->for($ingredient, 'ingredient') for the composite-unique
 * + tenant consistency. Tests that skip will get random
 * unrelated parents — almost never what they want.
 */
class RestockRequestLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'restock_request_id' => RestockRequest::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity_requested' => '5.000',
            'quantity_allocated' => '0.000',
            'unit_at_set' => IngredientUnit::Kilogram->value,
            'note' => null,
            'sort_order' => 0,
        ];
    }
}
