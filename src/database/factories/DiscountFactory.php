<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use App\Models\Company;
use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Discount>
 *
 * Default: an active "Test Discount" with a 10% off ORDER
 * scope, no validity window restrictions. Caller passes
 * ->for($company, 'company') for tenant consistency.
 */
class DiscountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => 'Test Discount ' . strtoupper(Str::random(4)),
            'scope' => DiscountScope::Order->value,
            'amount_type' => DiscountAmountType::Percent->value,
            'amount' => '10.000',
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'stackable' => false,
            'requires_manager_approval' => false,
            'status' => DiscountStatus::Active->value,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => DiscountStatus::Paused->value]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => DiscountStatus::Expired->value]);
    }

    public function productScope(): static
    {
        return $this->state(fn (): array => ['scope' => DiscountScope::Product->value]);
    }

    public function categoryScope(): static
    {
        return $this->state(fn (): array => ['scope' => DiscountScope::Category->value]);
    }

    public function fixed(string $amount): static
    {
        return $this->state(fn () => [
            'amount_type' => DiscountAmountType::Fixed->value,
            'amount' => $amount,
        ]);
    }

    public function stackable(): static
    {
        return $this->state(fn (): array => ['stackable' => true]);
    }
}
