<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shift>
 *
 * Default: an OPEN shift with 50.000 OMR float, no close yet.
 * closed() state seals it with a clean count (variance=0).
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'device_id' => null,
            'staff_id' => null,
            'opened_at' => now(),
            'closed_at' => null,
            'opening_cash' => '50.000',
            'closing_cash' => null,
            'expected_cash' => null,
            'variance' => null,
            'status' => ShiftStatus::Open->value,
            'note' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => ShiftStatus::Closed->value,
            'closed_at' => now()->addHours(8),
            'closing_cash' => '150.000',
            'expected_cash' => '150.000',
            'variance' => '0.000',
        ]);
    }

    public function short(string $variance = '-5.000'): static
    {
        return $this->state(function () use ($variance): array {
            $expected = 150.0;
            $closing = $expected + (float) $variance;
            return [
                'status' => ShiftStatus::Closed->value,
                'closed_at' => now()->addHours(8),
                'expected_cash' => '150.000',
                'closing_cash' => number_format($closing, 3, '.', ''),
                'variance' => $variance,
            ];
        });
    }
}
