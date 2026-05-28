<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoyaltyAccount>
 *
 * Default: an empty account (0 stamps, 0 points). Caller passes
 * ->for($company, 'company'), ->for($customer, 'customer') and
 * ->for($rule, 'rule') for tenant + relation consistency.
 */
class LoyaltyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'customer_id' => Customer::factory(),
            'loyalty_rule_id' => LoyaltyRule::factory(),
            'stamp_count' => 0,
            'point_balance' => 0,
            'last_activity_at' => null,
        ];
    }

    public function withPoints(int $points): static
    {
        return $this->state(fn (): array => ['point_balance' => $points, 'last_activity_at' => now()]);
    }

    public function withStamps(int $stamps): static
    {
        return $this->state(fn (): array => ['stamp_count' => $stamps, 'last_activity_at' => now()]);
    }
}
