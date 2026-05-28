<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\DeliveryProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryProvider>
 *
 * Default: an active "Provider XYZ" with a random 3-char
 * suffix so factory()->count(N) on one company doesn't trip
 * the (company_id, name) unique constraint. Caller passes
 * ->for($company, 'company') for tenant consistency.
 */
class DeliveryProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => 'Provider ' . strtoupper(Str::random(3)),
            'color' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
