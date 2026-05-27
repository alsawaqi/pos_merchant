<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Lane A — Device factory for tests.
 *
 * Default state mirrors what pos_admin's RegisterDeviceAction
 * + AssignDeviceAction produce in production: a device that
 * has been registered AND assigned to a branch. Tests that
 * exercise the unassigned / decommissioned / suspended paths
 * use the named states below.
 *
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'name' => 'Test Device ' . fake()->unique()->numerify('###'),
            'serial_number' => fake()->unique()->ean8(),
            'kiosk_id' => 'sf_' . Str::random(16),
            'device_type' => 'cashier',
            'status' => 'assigned',
            'assigned_at' => now()->subHour(),
        ];
    }

    /**
     * A device registered in the admin portal but not yet
     * assigned to a branch — Sanctum-auth requests should
     * reject (isOperable() returns false).
     */
    public function registered(): static
    {
        return $this->state(fn (): array => [
            'company_id' => null,
            'branch_id' => null,
            'status' => 'registered',
            'assigned_at' => null,
        ]);
    }

    public function decommissioned(): static
    {
        return $this->state(fn (): array => [
            'status' => 'decommissioned',
        ]);
    }
}
