<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderItemStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 *
 * Default: a 1-qty line at 2.500 OMR (= line_total). Caller
 * MUST pass ->for($order, 'order') and ->for($product,
 * 'product') for parent linking + the product snapshot.
 *
 * recipe_snapshot_json defaults to null (no recipe). Tests
 * that need the snapshotted recipe should pass it explicitly
 * or pull it from product->recipeLines() at write time.
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name_snapshot' => 'Item',
            'qty' => '1.000',
            'unit_price_snapshot' => '2.500',
            'line_discount' => '0.000',
            'line_total' => '2.500',
            'recipe_snapshot_json' => null,
            'status' => OrderItemStatus::Open->value,
            'notes' => null,
        ];
    }

    public function sentToKitchen(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderItemStatus::SentToKitchen->value,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderItemStatus::Void->value,
        ]);
    }
}
