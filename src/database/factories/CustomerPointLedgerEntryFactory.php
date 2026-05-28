<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PointLedgerEntryType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPointLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerPointLedgerEntry>
 *
 * Default: a +50-point adjustment with no reference. Caller
 * MUST pass ->for($customer, 'customer') and ->for($company,
 * 'company') for tenant consistency.
 *
 * IMPORTANT: a ledger row built via this factory does NOT
 * update the parent customer's points_balance. Tests that
 * need the customers <-> ledger invariant should call
 * WritePointLedgerEntryAction instead.
 */
class CustomerPointLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'company_id' => Company::factory(),
            'entry_type' => PointLedgerEntryType::Adjustment->value,
            'points_delta' => 50,
            'balance_after' => 50,
            'reason' => 'Pilot grant',
            'reference_type' => null,
            'reference_id' => null,
            'recorded_by_user_id' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
