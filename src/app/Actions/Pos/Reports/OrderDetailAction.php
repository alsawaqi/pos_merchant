<?php

declare(strict_types=1);

namespace App\Actions\Pos\Reports;

use App\Actions\Pos\Reports\Support\SaleCommissionStatus;
use App\Models\Order;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Merchant single-order DETAIL (v2 #2 — the keystone read-view).
 *
 * Loads one order by uuid, tenant-scoped, with everything a merchant
 * needs to answer "what happened on this order":
 *   - header: branch, staff (who served), customer, type/status/source,
 *     device (which terminal), vehicle plate, dine-in table(s) incl.
 *     joined tables, delivery-provider block, void reason, timestamps,
 *     note, money totals (incl. comp_total so the arithmetic reconciles)
 *   - line items (+ add-ons) with per-line discount + the promo name(s)
 *     that hit that line (#4 per-product discount visibility) and any
 *     per-line comps/gifts
 *   - order-level discounts in effect (#4 whole-order discount
 *     visibility; offer-engine rows flagged is_offer) + order-level comps
 *   - payments (method, auth code/RRN for card, charity round-up)
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
                'device:id,name',
                'table:id,label',
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
                // Engine-applied offer (vs a manual discount) — lets the UI
                // badge the row so a merchant can tell promos apart.
                'is_offer' => $row->offer_id !== null,
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

        // Comps + gifts (manager comps carry a reason snapshot; gift rows
        // carry is_gift). Order-level rows have order_item_id IS NULL.
        // Without these the drawer's arithmetic wouldn't reconcile on a
        // comped order (grand = subtotal − discount − comp + tax).
        $compRows = DB::table('pos_order_comps')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $lineComps = [];
        $orderComps = [];
        foreach ($compRows as $row) {
            $entry = [
                'reason' => $row->reason_name_snapshot !== null ? (string) $row->reason_name_snapshot : null,
                'amount' => number_format((float) $row->amount, 3, '.', ''),
                'is_gift' => (bool) $row->is_gift,
                'note' => $row->note,
            ];
            if ($row->order_item_id === null) {
                $orderComps[] = $entry;
            } else {
                $lineComps[(int) $row->order_item_id][] = $entry;
            }
        }

        $items = $order->items->map(static function ($item) use ($lineDiscountNames, $lineComps): array {
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
                // Comp/gift rows that hit this specific line, if any.
                'comps' => $lineComps[(int) $item->id] ?? [],
            ];
        })->all();

        // Dine-in table(s): the primary table plus any joined tables from
        // the pos_order_tables pivot (join-table billing). Soft-deleted
        // tables still resolve for historical sittings.
        $tables = [];
        if ($order->table !== null) {
            $tables[] = ['id' => (int) $order->table->id, 'label' => (string) $order->table->label];
        }
        $joined = DB::table('pos_order_tables')
            ->join('pos_tables', 'pos_tables.id', '=', 'pos_order_tables.table_id')
            ->where('pos_order_tables.order_id', $order->id)
            ->orderBy('pos_order_tables.id')
            ->get(['pos_tables.id', 'pos_tables.label']);
        foreach ($joined as $row) {
            $tables[] = ['id' => (int) $row->id, 'label' => (string) $row->label];
        }

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
                // Which terminal rang it up (main POS / handheld / tablet
                // unit) — soft-deleted devices still resolve.
                'device' => $order->device !== null
                    ? ['id' => (int) $order->device->id, 'name' => (string) $order->device->name]
                    : null,
                // Primary + joined dine-in tables (empty for non-dine-in).
                'tables' => $tables,
                // P-G7 delivery-provider block (null for non-provider orders).
                'delivery' => $order->delivery_provider_id !== null || $order->delivery_provider_name !== null
                    ? [
                        'provider_name' => $order->delivery_provider_name
                            ?? $order->deliveryProvider?->name,
                        'reference' => $order->delivery_reference,
                        'customer_phone' => $order->delivery_customer_phone,
                        'commission_percent' => $order->delivery_commission_percent !== null
                            ? (string) $order->delivery_commission_percent : null,
                        'expected_payout' => $order->delivery_expected_payout !== null
                            ? (string) $order->delivery_expected_payout : null,
                        'confirmed_at' => $order->delivery_confirmed_at?->format('Y-m-d\TH:i:s'),
                    ]
                    : null,
                // Why a void order was voided (label snapshot; null otherwise).
                'void_reason' => $order->void_reason_label,
                'totals' => [
                    'subtotal' => (string) $order->subtotal,
                    'discount_total' => (string) $order->discount_total,
                    'comp_total' => $order->comp_total !== null
                        ? number_format((float) $order->comp_total, 3, '.', '') : '0.000',
                    'tax_total' => (string) $order->tax_total,
                    'grand_total' => (string) $order->grand_total,
                ],
            ],
            'items' => $items,
            'order_discounts' => $orderDiscounts,
            'order_comps' => $orderComps,
            'payments' => $order->payments->map(static fn ($p): array => [
                'method' => $p->method?->value,
                'amount' => (string) $p->amount,
                'change_given' => $p->change_given !== null ? (string) $p->change_given : null,
                'status' => $p->status?->value,
                'softpos_auth_code' => $p->softpos_auth_code,
                'softpos_reference' => $p->softpos_reference,
                // Charity round-up riding this tender (null when none).
                'roundup_amount' => $p->roundup_amount !== null ? (string) $p->roundup_amount : null,
                'captured_at' => $p->captured_at?->format('Y-m-d\TH:i:s'),
            ])->all(),
            'loyalty' => $this->loyalty($companyId, (int) $order->id),
            // Commission split + reconciliation/payout status for this sale
            // (settled-aware; final only once the payout is paid). A no-commission
            // order is valued at the COLLECTED amount (grand_total − gift tenders).
            'commission' => SaleCommissionStatus::forOrders($companyId, [(int) $order->id])[(int) $order->id]
                ?? SaleCommissionStatus::none(
                    (string) $order->grand_total,
                    SaleCommissionStatus::giftTotals($companyId, [(int) $order->id])[(int) $order->id] ?? 0.0,
                ),
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
