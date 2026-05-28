<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountTargetType;
use App\Models\Discount;
use App\Models\DiscountTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountTarget>
 *
 * Default: a product target with a placeholder target_id.
 * Caller MUST pass ->for($discount, 'discount') AND set
 * target_id to a real product/category id for tenant
 * consistency.
 */
class DiscountTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discount_id' => Discount::factory(),
            'target_type' => DiscountTargetType::Product->value,
            'target_id' => 1,
        ];
    }

    public function category(): static
    {
        return $this->state(fn (): array => [
            'target_type' => DiscountTargetType::Category->value,
        ]);
    }
}
