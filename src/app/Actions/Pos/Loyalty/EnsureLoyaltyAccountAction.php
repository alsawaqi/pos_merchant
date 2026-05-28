<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Support\MerchantTenantContext;
use RuntimeException;

/**
 * Loyalty refactor — find-or-create a customer's account under a
 * rule. One account per (customer, rule); the composite-unique
 * index makes this a single round-trip.
 *
 * Both the customer and the rule must belong to the acting tenant.
 */
final readonly class EnsureLoyaltyAccountAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Customer $customer, LoyaltyRule $rule): LoyaltyAccount
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $customer->company_id !== $companyId) {
            throw new RuntimeException('Customer does not belong to this company.');
        }
        if ((int) $rule->company_id !== $companyId) {
            throw new RuntimeException('Loyalty rule does not belong to this company.');
        }

        /** @var LoyaltyAccount $account */
        $account = LoyaltyAccount::query()->firstOrCreate(
            [
                'customer_id' => $customer->id,
                'loyalty_rule_id' => $rule->id,
            ],
            [
                'company_id' => $companyId,
            ],
        );

        return $account;
    }
}
