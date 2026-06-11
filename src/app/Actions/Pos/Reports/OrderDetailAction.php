<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Models\Order;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Merchant single-order DETAIL (v2 #2 — the keystone read-view).
 *
 * Loads one order by uuid, tenant-scoped, with everything a merchant
 * needs to answer "what happened on this order":
 *   - header: branch, staff (who served), customer, type/status/source,
 *     vehicle plate, timestamps, note, money totals
 *   - line items (+ add-ons) with per-line discount + the promo name(s)
 *     that hit that line (#4 per-product discount visibility)
 *   - order-level discounts in effect (#4 whole-order discount visibility)
 *   - payments (method, auth code/RRN for card)
 *   - loyalty points/stamps earned + redeemed on this order (#2 points
 *     gained, with the txn ledger for that order)
 *
 * Returns null when the uuid doesn't belong to the tenant — the
 * controller turns that into a 404 (never leaks another company's order).
 */
final readonly class OrderDetailAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $uuid): ?array
    {
        $companyId = $this->tenant->requiredId();

        $order = Order::query()
            ->with([
                'branch:id,name',
                'customer:id,name,phone',
                'staff:id,name',
                'items.addons',
                'payments',
            ])
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->first();

        if ($order === null) {
            return null;
        }

        // All recorded discount applications for this order. Order-level
        // rows have order_item_id IS NULL; the rest attach to a line.
        $discountRows = DB::table('pos_order_discounts')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $lineDiscountNames = [];
        $orderDiscounts = [];
        foreach ($discountRows as $row) {
            $entry = [
                'name' => (string) $row->name_snapshot,
                'amount_type' => $row->amount_type_snapshot !== null ? (string) $row->amount_type_snapshot : null,
                'amount' => number_format((float) $row->amount, 3, '.', ''),
                'applied_at' => $row->applied_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->applied_at)->format('Y-m-d\TH:i:s')
                    : null,
            ];
            if ($row->order_item_id === null) {
                $orderDiscounts[] = $entry;
            } else {
                $lineDiscountNames[(int) $row->order_item_id][] = $entry;
            }
        }

        $items = $order->items->map(static function ($item) use ($lineDiscountNames): array {
            return [
                'id' => (int) $item->id,
                'product_name' => (string) $item->product_name_snapshot,
                'qty' => (string) $item->qty,
                'unit_price' => (string) $item->unit_price_snapshot,
                'line_discount' => (string) $item->line_discount,
                'line_total' => (string) $item->line_total,
                'notes' => $item->notes,
                'addons' => $item->addons->map(static fn ($a): array => [
                    'name' => (string) $a->add_on_name_snapshot,
                    'price_delta' => (string) $a->price_delta_snapshot,
                ])->all(),
                // Promo name(s) that hit this specific line, if any.
                'discounts' => $lineDiscountNames[(int) $item->id] ?? [],
            ];
        })->all();

        return [
            'order' => [
                'id' => (int) $order->id,
                'uuid' => $order->uuid,
                // P-F8 — the printed receipt number; null for unnumbered
                // orders (the UI falls back to the short uuid).
                'receipt_number' => $order->receipt_number,
                'order_type' => $order->order_type?->value,
                'status' => $order->status?->value,
                'source' => $order->source?->value,
                'plate_number' => $order->plate_number,
                'note' => $order->note,
                'opened_at' => $order->opened_at?->format('Y-m-d\TH:i:s'),
                'closed_at' => $order->closed_at?->format('Y-m-d\TH:i:s'),
                'branch' => $order->branch !== null
                    ? ['id' => (int) $order->branch->id, 'name' => (string) $order->branch->name]
                    : null,
                'customer' => $order->customer !== null
                    ? [
                        'id' => (int) $order->customer->id,
                        'name' => (string) $order->customer->name,
                        'phone' => $order->customer->phone,
                    ]
                    : null,
                'staff' => $order->staff !== null
                    ? ['id' => (int) $order->staff->id, 'name' => (string) $order->staff->name]
                    : null,
                'totals' => [
                    'subtotal' => (string) $order->subtotal,
                    'discount_total' => (string) $order->discount_total,
                    'tax_total' => (string) $order->tax_total,
                    'grand_total' => (string) $order->grand_total,
                ],
            ],
            'items' => $items,
            'order_discounts' => $orderDiscounts,
            'payments' => $order->payments->map(static fn ($p): array => [
                'method' => $p->method?->value,
                'amount' => (string) $p->amount,
                'change_given' => $p->change_given !== null ? (string) $p->change_given : null,
                'status' => $p->status?->value,
                'softpos_auth_code' => $p->softpos_auth_code,
                'softpos_reference' => $p->softpos_reference,
                'captured_at' => $p->captured_at?->format('Y-m-d\TH:i:s'),
            ])->all(),
            'loyalty' => $this->loyalty($companyId, (int) $order->id),
        ];
    }

    /**
     * Loyalty points/stamps moved on this order, plus the raw txn rows.
     *
     * @return array<string, mixed>
     */
    private function loyalty(int $companyId, int $orderId): array
    {
        $rows = DB::table('pos_loyalty_transactions')
            ->where('company_id', $companyId)
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        $pointsEarned = 0;
        $pointsRedeemed = 0;
        $stampsEarned = 0;
        $stampsRedeemed = 0;
        $transactions = [];
        foreach ($rows as $row) {
            $pd = (int) $row->points_delta;
            $sd = (int) $row->stamps_delta;
            if ($pd >= 0) {
                $pointsEarned += $pd;
            } else {
                $pointsRedeemed += -$pd;
            }
            if ($sd >= 0) {
                $stampsEarned += $sd;
            } else {
                $stampsRedeemed += -$sd;
            }
            $transactions[] = [
                'type' => (string) $row->type,
                'points_delta' => $pd,
                'stamps_delta' => $sd,
                'occurred_at' => $row->occurred_at !== null
                    ? \Illuminate\Support\Carbon::parse($row->occurred_at)->format('Y-m-d\TH:i:s')
                    : null,
            ];
        }

        return [
            'points_earned' => $pointsEarned,
            'points_redeemed' => $pointsRedeemed,
            'stamps_earned' => $stampsEarned,
            'stamps_redeemed' => $stampsRedeemed,
            'transactions' => $transactions,
        ];
    }
}
