<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * Default: a quick / cash / open order at zero totals. The
 * seeder + test specs fill in realistic totals + items. Caller
 * should pass ->for($company, 'company') and ->for($branch,
 * 'branch') for tenant consistency.
 *
 * IMPORTANT: factory-built orders bypass the Phase 8
 * OrderAction's invariant checks (subtotal - discount + tax
 * == grand_total + SUM(payments) == grand_total). Tests
 * that need the full invariant should call the Action layer
 * instead.
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'device_id' => null,
            'staff_id' => null,
            'customer_id' => null,
            'table_id' => null,
            'order_type' => OrderType::Quick->value,
            'status' => OrderStatus::Open->value,
            'source' => OrderSource::MainPos->value,
            'plate_number' => null,
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '0.000',
            'opened_at' => now(),
            'closed_at' => null,
            'client_event_id' => 'evt_' . strtolower(Str::random(16)),
            'note' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid->value,
            'closed_at' => now(),
        ]);
    }

    public function held(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Held->value,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Void->value,
            'closed_at' => now(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Refunded->value,
            'closed_at' => now(),
        ]);
    }

    public function dineIn(): static
    {
        return $this->state(fn (): array => [
            'order_type' => OrderType::DineIn->value,
        ]);
    }

    public function delivery(): static
    {
        return $this->state(fn (): array => [
            'order_type' => OrderType::Delivery->value,
        ]);
    }
}
