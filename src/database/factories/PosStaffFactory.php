<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffPosition;
use App\Enums\StaffStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PosStaff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosStaff>
 *
 * Default: an active cashier with a deterministic test PIN
 * (`123456`) hashed under bcrypt. The deterministic PIN lets
 * tests assert PIN verification semantics without re-implementing
 * the random generator.
 *
 * The factory does NOT auto-create a Branch — branch_id is left
 * for the caller to provide via ->for($branch) (or via state) so
 * the branch's company_id is guaranteed to match this staff
 * member's company_id. Auto-spawning would create two unrelated
 * Company rows and trip the cross-tenant guard.
 */
class PosStaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'name' => fake()->name(),
            'phone' => '+968'.fake()->numerify('########'),
            'staff_code' => null,
            'pin_hash' => Hash::make('123456'),
            'position' => StaffPosition::Cashier->value,
            'status' => StaffStatus::Active->value,
            'hired_at' => fake()->dateTimeBetween('-2 years', '-1 week')->format('Y-m-d'),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => StaffStatus::Suspended->value,
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (): array => [
            'status' => StaffStatus::Terminated->value,
            'terminated_at' => now(),
            'deleted_at' => now(),
        ]);
    }

    public function waiter(): static
    {
        return $this->state(fn (): array => [
            'position' => StaffPosition::Waiter->value,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (): array => [
            'position' => StaffPosition::Manager->value,
        ]);
    }
}
