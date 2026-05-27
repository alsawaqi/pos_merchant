<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RestockRequestStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\RestockRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RestockRequest>
 *
 * Default: a Draft request with no lines. Caller passes
 * ->for($company) and ->for($branch) to keep tenant
 * consistency; the branch's company_id should match.
 *
 * State methods cover each lifecycle status. Use these in
 * tests that need a request already in a given state without
 * having to drive the full submit-approve-allocate chain.
 *
 * Lines are NOT auto-created — chain ->has(RestockRequestLine::factory()->count(N), 'lines')
 * or attach them manually for the test scenario.
 */
class RestockRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'branch_id' => Branch::factory(),
            'status' => RestockRequestStatus::Draft->value,
            'requested_by_user_id' => null,
            'submitted_at' => null,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'fulfilled_at' => null,
            'note' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => RestockRequestStatus::Submitted->value,
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => RestockRequestStatus::Approved->value,
            'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now(),
        ]);
    }

    public function fulfilled(): static
    {
        return $this->state(fn (): array => [
            'status' => RestockRequestStatus::Fulfilled->value,
            'submitted_at' => now()->subHours(2),
            'reviewed_at' => now()->subHour(),
            'fulfilled_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Out of stock'): static
    {
        return $this->state(fn (): array => [
            'status' => RestockRequestStatus::Rejected->value,
            'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now(),
            'review_note' => $reason,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => RestockRequestStatus::Cancelled->value,
        ]);
    }
}
