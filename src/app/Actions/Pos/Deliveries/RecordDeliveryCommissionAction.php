<?php

declare(strict_types=1);

namespace App\Actions\Pos\Deliveries;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P-G7 — records the per-sale commission breakdown for a CONFIRMED
 * delivery order, the moment the money becomes real. Port of pos_api's
 * RecordSaleCommissionAction (and pos_admin's reconciliation twin) with
 * the delivery-specific bases:
 *
 *   - collected  = the amount the provider ACTUALLY paid
 *     (delivery_received_amount) — the platform/other parties take their
 *     cut of money that arrived, not of the punched price;
 *   - card money = 0: no till tender existed, so the bank (acquirer)
 *     share line gets nothing and the merchant keeps that slice — the
 *     same rule as cash/bank_pos sales (P-F5).
 *
 * Same invariants as the twins: idempotent via the one-breakdown-per-
 * order guard (UNIQUE (order_id, sort_order) backs it up), percents
 * snapshotted per row, the merchant takes the exact rounding remainder
 * so Σ(rows) == collected to the baisa, occurred_at = confirmation time
 * (revenue is dated at confirmation everywhere for deliveries).
 *
 * No active profile / no shares / nothing received ⇒ no rows (the
 * merchant keeps 100% — the blueprint default). pos_merchant has no
 * commission models, so this reads/writes the shared tables directly.
 */
final readonly class RecordDeliveryCommissionAction
{
    private const PARTY_BANK = 'bank';

    /**
     * @return list<int> ids of the created sale-commission rows
     */
    public function handle(Order $order): array
    {
        // Idempotency: one breakdown per order, ever (same guard as the
        // pay-time and reconciliation-time writers).
        $exists = DB::table('pos_sale_commissions')->where('order_id', $order->id)->exists();
        if ($exists) {
            return [];
        }

        // The ledger's device_id is NOT NULL — an orphaned order whose
        // device row was deleted records nothing (the admin twin's rule).
        if ($order->device_id === null) {
            return [];
        }

        $profile = DB::table('pos_commission_profiles')
            ->where('company_id', $order->company_id)
            ->where('is_active', true)
            ->first();
        if ($profile === null) {
            return [];
        }

        $shares = DB::table('pos_commission_shares')
            ->where('commission_profile_id', $profile->id)
            ->orderBy('sort_order')
            ->get();
        if ($shares->isEmpty()) {
            return [];
        }

        $grossBaisas = (int) round(((float) $order->grand_total) * 1000);
        $collectedBaisas = (int) round(((float) ($order->delivery_received_amount ?? 0)) * 1000);
        if ($collectedBaisas <= 0) {
            return [];
        }
        $occurredAt = $order->delivery_confirmed_at ?? now();

        $rows = [];
        $sortOrder = 0;
        $allocatedBaisas = 0;

        foreach ($shares as $share) {
            $percent = (float) $share->percent;
            // No card money on a delivery order — the bank line gets 0.
            $base = $share->party_type === self::PARTY_BANK ? 0 : $collectedBaisas;
            $amountBaisas = (int) round($base * $percent / 100);
            $allocatedBaisas += $amountBaisas;

            $rows[] = [
                'party_type' => (string) $share->party_type,
                'party_label' => (string) $share->label,
                'percent' => $percent,
                'amount_baisas' => $amountBaisas,
                'sort_order' => $sortOrder++,
            ];
        }

        // The merchant takes the exact remainder — Σ(rows) == collected.
        $rows[] = [
            'party_type' => 'merchant',
            'party_label' => 'Merchant',
            'percent' => (float) $profile->merchant_percent,
            'amount_baisas' => $collectedBaisas - $allocatedBaisas,
            'sort_order' => $sortOrder,
        ];

        // Atomic like both twins (pos_api writes inside the pay transaction;
        // the pos_admin reconciliation twin wraps its rows in their own
        // transaction): a partial breakdown would be frozen forever by the
        // exists() guard above, silently dropping the merchant's residual.
        return DB::transaction(function () use ($rows, $order, $profile, $grossBaisas, $occurredAt): array {
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) DB::table('pos_sale_commissions')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'device_id' => $order->device_id,
                    'order_id' => $order->id,
                    'payment_id' => null,
                    'commission_profile_id' => $profile->id,
                    'party_type' => $row['party_type'],
                    'party_label' => $row['party_label'],
                    'percent' => $row['percent'],
                    'gross_amount' => number_format($grossBaisas / 1000, 3, '.', ''),
                    'commission_amount' => number_format($row['amount_baisas'] / 1000, 3, '.', ''),
                    'sort_order' => $row['sort_order'],
                    'client_event_id' => null,
                    'occurred_at' => $occurredAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $ids;
        });
    }
}
