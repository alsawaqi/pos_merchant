<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 *
 * Default: a customer with a fake name and a random-suffixed
 * Oman-style phone number. The random suffix avoids the
 * (company_id, phone) unique-index trip when factory()->count(n)
 * is used in a single test.
 *
 * Caller should pass ->for($company, 'company') for tenant
 * consistency — otherwise a fresh Company is auto-created
 * which is rarely what tests want.
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            // Oman mobile pattern: +968 9XXXXXXXX. Microtime-
            // sourced 8-digit suffix keeps collisions on the
            // (company_id, phone) unique index effectively zero
            // even when a single test factory()->count()s a few
            // dozen rows.
            'phone' => '+968 9' . str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT),
        ];
    }
}
