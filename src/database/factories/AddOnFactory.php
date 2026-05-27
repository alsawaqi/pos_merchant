<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Phase 4.9 — AddOn factory.
 *
 * Caller usually does ->for($group, 'group') so the addon's
 * company_id stays consistent with its group's company_id.
 * The factory auto-spawns a group + company chain if not
 * supplied.
 *
 * @extends Factory<AddOn>
 */
class AddOnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'add_on_group_id' => AddOnGroup::factory(),
            'name' => fake()->words(2, true),
            'name_ar' => null,
            // 0.000 to 1.000 OMR — typical add-on range
            // (extra shot, oat milk swap, etc.).
            'price_delta' => fake()->randomFloat(3, 0, 1.000),
            'ingredient_id' => null,
            'ingredient_qty' => null,
            'ingredient_unit' => null,
            'display_order' => 0,
            'status' => 'active',
        ];
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['price_delta' => 0.000]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
