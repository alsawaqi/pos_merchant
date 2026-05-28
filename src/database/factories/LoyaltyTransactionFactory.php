<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoyaltyTransactionType;
use App\Models\Company;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoyaltyTransaction>
 *
 * Default: a +50-point adjust entry. Caller passes ->for($account,
 * 'account') and ->for($company, 'company').
 *
 * IMPORTANT: a transaction built via this factory does NOT update
 * the account's running balance. Tests that need the full
 * invariant should call WriteLoyaltyTransactionAction instead.
 */
class LoyaltyTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'loyalty_account_id' => LoyaltyAccount::factory(),
            'type' => LoyaltyTransactionType::Adjust->value,
            'points_delta' => 50,
            'stamps_delta' => 0,
            'balance_after_points' => 50,
            'balance_after_stamps' => 0,
            'reason' => 'Test adjustment',
            'order_id' => null,
            'recorded_by_user_id' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }

    public function earn(int $points = 10): static
    {
        return $this->state(fn (): array => [
            'type' => LoyaltyTransactionType::Earn->value,
            'points_delta' => $points,
            'balance_after_points' => $points,
            'reason' => null,
        ]);
    }

    public function redeem(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => LoyaltyTransactionType::Redeem->value,
            'points_delta' => -abs($points),
            'balance_after_points' => 0,
            'reason' => null,
        ]);
    }
}
