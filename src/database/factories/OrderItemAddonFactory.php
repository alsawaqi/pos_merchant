<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItemAddon>
 *
 * Default: a +0.500 OMR modifier. Caller MUST pass
 * ->for($orderItem, 'orderItem') and ->for($addOn, 'addOn').
 */
class OrderItemAddonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'add_on_id' => AddOn::factory(),
            'add_on_name_snapshot' => 'Extra',
            'price_delta_snapshot' => '0.500',
            'ingredient_snapshot_json' => null,
        ];
    }
}
