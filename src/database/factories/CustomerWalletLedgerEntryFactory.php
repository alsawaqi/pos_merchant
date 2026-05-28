<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WalletLedgerEntryType;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerWalletLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerWalletLedgerEntry>
 *
 * Default: a +5.000 OMR top-up entry. Caller MUST pass
 * ->for($customer, 'customer') and ->for($company, 'company')
 * for tenant consistency. Bypasses balance update (use
 * WriteWalletLedgerEntryAction for the full invariant).
 */
class CustomerWalletLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_id' => Customer::factory(),
            'company_id' => Company::factory(),
            'entry_type' => WalletLedgerEntryType::TopUp->value,
            'amount_delta' => '5.000',
            'balance_after' => '5.000',
            'reason' => 'Cash topup at counter',
            'reference_type' => null,
            'reference_id' => null,
            'recorded_by_user_id' => null,
            'occurred_at' => now(),
            'created_at' => now(),
        ];
    }
}
