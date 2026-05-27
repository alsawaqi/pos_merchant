<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Builds a `pos_companies` row for tests. The pos_merchant Company
 * model is read-only at the application layer ($guarded='*') —
 * Factory bypasses that via forceFill, which is exactly what tests
 * need.
 *
 * Only the columns present in the test-schema migration are set
 * here. Production has more (CR/VAT/i18n fields) but pos_merchant
 * tests never read them, so they're absent on both sides.
 *
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->company(),
            'legal_name' => fake()->company().' LLC',
            'commercial_registration_number' => (string) fake()->unique()->numerify('1#######'),
            'tax_number' => (string) fake()->numerify('OM##########'),
            'contact_name' => fake()->name(),
            'contact_phone' => '+968'.fake()->numerify('########'),
            'contact_email' => fake()->companyEmail(),
            'status' => 'active',
            'settings' => [],
        ];
    }
}
