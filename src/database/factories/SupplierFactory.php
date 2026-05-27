<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        // Random suffix avoids tripping the
        // unique(company_id, name) index when a test creates
        // multiple suppliers under the same company.
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => fake()->company() . ' ' . strtoupper(Str::random(3)),
            'contact' => fake()->phoneNumber(),
            'notes' => null,
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
