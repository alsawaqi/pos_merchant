<?php

declare(strict_types=1);

namespace App\Actions\Pos\Deliveries;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G7 — confirm pending-verification delivery orders against the
 * provider's statement (the P-F7 approval-queue mechanics, merchant-side).
 *
 * Two modes through one path:
 *   confirm (bulk)  — the statement matched: received = the expected
 *                     payout frozen at punch, variance 0;
 *   adjust (single) — the provider paid a different amount: received is
 *                     explicit, variance = received − expected, recorded
 *                     as the reconciliation trail.
 *
 * Per order, inside one transaction: status → paid, and the order is
 * RE-DATED to the confirmation moment (opened_at + closed_at = now) —
 * "the sale counts when the money was received". The original punch
 * moment survives in delivery_punched_at. AFTER the flip commits, the
 * commission split fires idempotently (RecordDeliveryCommissionAction) —
 * money effects never sit inside the flip transaction, mirroring
 * pos_admin's ReconcileDeferredEffectsAction.
 *
 * F5: every order's branch is checked against the actor's scope (403 on
 * any out-of-scope id — the whole batch is refused before any flip, so a
 * partial bulk never half-applies).
 */
final readonly class ConfirmDeliveryOrdersAction
{
    public function __construct(
        private RecordDeliveryCommissionAction $recordCommission,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @param  numeric-string|float|null  $receivedAmount  explicit received amount
     *                                                     (single-order adjust only)
     * @return array{orders_confirmed: int, commissions_recorded: int}
     */
    public function handle(array $orderIds, User $actor, float|string|null $receivedAmount = null): array
    {
        $companyId = $this->tenant->requiredId();
        $orderIds = array_values(array_unique(array_map(intval(...), $orderIds)));

        if ($receivedAmount !== null && count($orderIds) !== 1) {
            throw new RuntimeException('An adjusted amount applies to exactly one order.');
        }

        $orders = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $orderIds)
            ->get();

        // Unknown / cross-tenant ids: 404-equivalent (no information leak).
        if ($orders->count() !== count($orderIds)) {
            throw new RuntimeException('One or more orders were not found.');
        }

        foreach ($orders as $order) {
            if ($order->status !== OrderStatus::PendingVerification) {
                throw new RuntimeException('Order '.$order->uuid.' is not awaiting verification.');
            }
            // F5 — refuse the WHOLE batch before any flip.
            BranchScope::ensureBranch($actor, (int) $order->branch_id);
        }

        $confirmedIds = [];
        $commissionsRecorded = 0;

        foreach ($orders as $order) {
            DB::transaction(function () use ($order, $actor, $receivedAmount, $companyId): void {
                // Re-read UNDER LOCK: the pre-loop guard ran outside this
                // transaction, so a concurrent decision (second confirm, an
                // adjust, or a device void) could have settled the order in
                // the meantime — without this, a voided order could be
                // resurrected to paid, or the recorded received amount could
                // diverge from the commission rows the first writer split.
                $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();
                if ($fresh === null || $fresh->status !== OrderStatus::PendingVerification) {
                    throw new RuntimeException('Order '.$order->uuid.' is not awaiting verification.');
                }
                $order->setRawAttributes($fresh->getAttributes(), true);

                $expected = (float) ($order->delivery_expected_payout ?? 0);
                $received = $receivedAmount !== null ? (float) $receivedAmount : $expected;
                $variance = round($received - $expected, 3);
                $now = now();

                $order->forceFill([
                    'status' => OrderStatus::Paid->value,
                    // Revenue dated at confirmation: every report windows on
                    // opened_at, and closed_at is the terminal stamp. The
                    // punch moment lives on in delivery_punched_at.
                    'opened_at' => $now,
                    'closed_at' => $now,
                    'delivery_received_amount' => number_format($received, 3, '.', ''),
                    'delivery_variance' => number_format($variance, 3, '.', ''),
                    'delivery_confirmed_at' => $now,
                    'delivery_confirmed_by_user_id' => $actor->getKey(),
                ])->save();

                $this->writeAuditLog->handle(new AuditLogData(
                    event: 'deliveries.order_confirmed',
                    actorUserId: $actor->getKey(),
                    companyId: $companyId,
                    auditableType: Order::class,
                    auditableId: $order->id,
                    newValues: [
                        'provider' => $order->delivery_provider_name,
                        'reference' => $order->delivery_reference,
                        'expected_payout' => number_format($expected, 3, '.', ''),
                        'received_amount' => number_format($received, 3, '.', ''),
                        'variance' => number_format($variance, 3, '.', ''),
                    ],
                ));
            });

            $confirmedIds[] = (int) $order->id;
            // Deferred money effect AFTER the flip committed — the P-F7
            // rule that HTTP/side-effects never sit inside (nor roll back)
            // the flip transaction. Idempotent on the one-breakdown-per-
            // order guard.
            $commissionsRecorded += count($this->recordCommission->handle($order->refresh()));
        }

        return [
            'orders_confirmed' => count($confirmedIds),
            'commissions_recorded' => $commissionsRecorded,
        ];
    }
}
