<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CustomerLoyaltyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerLoyaltyConfig>
 *
 * Default: an ACTIVE config at "1 OMR = 1 point" and
 * "1 point = 0.010 OMR" (10 baisas/point). Caller passes
 * ->for($company, 'company') for tenant consistency.
 */
class CustomerLoyaltyConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'points_per_omr' => 1,
            'baisas_per_point' => 10,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
