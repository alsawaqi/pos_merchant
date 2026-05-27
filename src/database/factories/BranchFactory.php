<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 *
 * Auto-creates a parent Company when the test forgets to attach
 * one — matches the ergonomic feel of pos_admin's factory. Use
 * `Branch::factory()->for($company)->create()` when an existing
 * company should own the branch.
 */
class BranchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => fake()->city().' Branch',
            'code' => strtoupper(fake()->bothify('BR-###')),
            'status' => 'active',
            'settings' => [],
        ];
    }
}
