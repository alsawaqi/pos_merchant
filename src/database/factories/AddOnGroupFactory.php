<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AddOnSelectionMode;
use App\Models\AddOnGroup;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Phase 4.9 — AddOnGroup factory.
 *
 * Default state: a product-specific group ("Milk Choice ABC")
 * with single-select mode. Use ->global() for is_global=true
 * cases and ->multi() to flip the selection mode.
 *
 * Name suffix avoids unique(company_id, name) trip when a test
 * spins up multiple groups under the same company.
 *
 * @extends Factory<AddOnGroup>
 */
class AddOnGroupFactory extends Factory
{
    public function definition(): array
    {
        $base = fake()->randomElement(['Milk Choice', 'Sugar Level', 'Size', 'Extras', 'Toppings']);

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => $base . ' ' . strtoupper(Str::random(3)),
            'name_ar' => null,
            'selection_mode' => AddOnSelectionMode::Single->value,
            'is_global' => false,
            'display_order' => 0,
            'status' => 'active',
        ];
    }

    public function global(): static
    {
        return $this->state(fn (): array => ['is_global' => true]);
    }

    public function multi(): static
    {
        return $this->state(fn (): array => [
            'selection_mode' => AddOnSelectionMode::Multi->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
