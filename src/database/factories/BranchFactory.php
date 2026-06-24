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
     * Monotonic so two branches under the same company never collide on the
     * unique (company_id, code) — `bothify('BR-###')` had only 1000 values and
     * flaked the suite (~1/1000 per branch pair, compounding) and blocked CI.
     */
    private static int $codeSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => fake()->city().' Branch',
            'code' => sprintf('BR-%06d', ++self::$codeSequence),
            'status' => 'active',
            'settings' => [],
        ];
    }
}
