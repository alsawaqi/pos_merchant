<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Data\Reports\ReportFilter;
use App\Support\MerchantTenantContext;

/**
 * Phase 7b — Round-Up Donation Report (blueprint §5.11.9).
 *
 * STUBBED for Phase 7b. The blueprint Round-Up flow is:
 *
 *   - Cashier (or kiosk) offers "round up to nearest 100 baisas
 *     and donate the difference to {charity}"
 *   - The pos_orders row gets a donation_amount + donation_charity_id
 *     column on payment
 *   - This report aggregates: total raised in window, per-charity
 *     breakdown, per-branch breakdown, opt-in rate, payout schedule
 *
 * None of the donation infrastructure (round_up config, charity
 * directory, donation_amount column on pos_orders) is in the
 * schema yet -- those land in Phase 9 (the explicit blueprint
 * sequencing is Roundup -> charity invoicing -> charity payout).
 *
 * This Action returns a well-formed empty payload so:
 *   1) the Reports landing UI in 7b-6 can list every blueprint
 *      report (no missing tile), and
 *   2) when Phase 9 ships the donation columns, only the inside
 *      of this Action changes -- consumers keep working.
 */
final readonly class RoundUpDonationReportAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ReportFilter $filter): array
    {
        // Tenant scope still required so cross-tenant calls fail
        // (even though we return zeros, the act of CALLING for
        // another tenant should be prevented by the same surface).
        $this->tenant->requiredId();

        return [
            'window' => [
                'from' => $filter->dateFrom->format('Y-m-d\TH:i:s'),
                'to' => $filter->dateTo->format('Y-m-d\TH:i:s'),
                'consolidated' => $filter->consolidated,
                'branch_ids' => $filter->branchScope(),
            ],
            'headline' => [
                'total_raised' => '0.000',
                'donation_count' => 0,
                'opt_in_rate_pct' => 0.0,
            ],
            'by_charity' => [],
            'by_branch' => [],
            '_phase' => [
                'donation_stub' => 'Round-Up donation aggregation lands with Phase 9: pos_orders.donation_amount + pos_charities directory + Round-Up config. This Action returns a zeroed payload so the Reports landing UI can list the tile now.',
            ],
        ];
    }
}
