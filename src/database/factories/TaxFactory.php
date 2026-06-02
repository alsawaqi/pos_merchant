<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tax>
 */
class TaxFactory extends Factory
{
    protected $model = Tax::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => 'Tax '.ucfirst(fake()->unique()->word()),
            'name_ar' => null,
            'rate_percent' => fake()->randomElement(['5.00', '2.00', '8.00']),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
