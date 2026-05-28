<?php

declare(strict_types=1);

namespace App\Actions\Pos\Orders;

use App\Enums\OrderItemStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 7a — deterministic demo-order seeder.
 *
 * The blueprint Phase 7 exit checklist demands:
 *
 *   "Seeded test data of 1000 orders produces correct,
 *    reconciled totals on every report."
 *
 * This Action is the canonical seed: callers pass a Company,
 * a target order count, and an optional random seed. Output
 * is REPRODUCIBLE given the same (company, count, seed) tuple
 * — feature tests reseed at the start of each test so the
 * report aggregations are checkable against known totals.
 *
 * Distribution choices (matching real cafe pilot patterns):
 *
 *   ORDER TYPE       quick 60%, dine_in 20%, to_go 15%,
 *                    delivery 3%, car 2%
 *
 *   PAYMENT METHOD   cash 40%, card 50%, split (cash+card) 10%
 *                    — see split_part rationale below
 *
 *   HOUR BUCKET      bias toward 12:00-14:00 + 19:00-21:00
 *                    (peak detection in §5.11.1 Sales Report)
 *
 *   CUSTOMER         attached ~30%; rest walk-in (customer_id
 *                    null). Matches the §5.11.8 Customer Report's
 *                    expected new-vs-returning split
 *
 *   ITEMS PER ORDER  1-4, weighted toward 1-2
 *
 * Split-tender note: when an order is marked "split", we write
 * TWO Payment rows BOTH with method=split_part summing to
 * grand_total. The reports group split_part as a single bucket
 * (§5.11.1 Sales Report payment-method breakdown).
 *
 * The Action does NOT call Phase 8's OrderAction (which doesn't
 * exist yet) — it writes directly via the models so the seeded
 * data shape matches what the Phase 8 pipeline will produce
 * once it's built. Once Phase 8 lands, the Action layer's
 * invariant checks will validate this seeder's output too.
 *
 * Currency: all amounts are quantised to baisas (0.001 OMR)
 * via number_format(x, 3, '.', '') so the SUM(payments) ==
 * grand_total invariant holds exactly (no float drift).
 */
final readonly class SeedDemoOrdersAction
{
    public function __construct() {}

    /**
     * @return array{orders: int, items: int, payments: int}
     */
    public function handle(
        Company $company,
        int $count,
        ?int $seed = null,
        ?Carbon $endingAt = null,
    ): array {
        if ($count <= 0) {
            throw new RuntimeException('Demo order count must be positive.');
        }

        // Deterministic randomness: seeding mt_srand at the
        // boundary keeps Faker AND our manual random picks in
        // lockstep across runs.
        $seed ??= 20260604;
        mt_srand($seed);

        $endingAt ??= now();

        $branches = Branch::query()->where('company_id', $company->id)->get();
        if ($branches->isEmpty()) {
            throw new RuntimeException('Cannot seed orders — company has no branches.');
        }

        $products = Product::query()->where('company_id', $company->id)->get();
        if ($products->isEmpty()) {
            throw new RuntimeException('Cannot seed orders — company has no products.');
        }

        $customers = Customer::query()->where('company_id', $company->id)->get();

        $orderCount = 0;
        $itemCount = 0;
        $paymentCount = 0;

        DB::transaction(function () use (
            $company,
            $branches,
            $products,
            $customers,
            $count,
            $endingAt,
            &$orderCount,
            &$itemCount,
            &$paymentCount,
        ): void {
            for ($i = 0; $i < $count; $i++) {
                $branch = $branches->random();
                $openedAt = $this->randomOpenedAt($endingAt, $i, $count);
                $orderType = $this->weightedOrderType();
                $itemsCount = $this->weightedItemsCount();

                $lineRows = [];
                $subtotal = 0.0;
                for ($j = 0; $j < $itemsCount; $j++) {
                    $product = $products->random();
                    $qty = 1;
                    $unitPrice = (float) $product->base_price;
                    $lineTotal = $qty * $unitPrice;
                    $subtotal += $lineTotal;
                    $lineRows[] = [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                }

                // No tax / discount in the demo seeder; reports
                // that test these dimensions seed their own
                // orders via a different code path. Keeping the
                // base seeder simple makes its totals exactly
                // SUM(line_total) which the report unit tests
                // can verify trivially.
                $grandTotal = $subtotal;

                $customer = $this->shouldAttachCustomer() && $customers->isNotEmpty()
                    ? $customers->random()
                    : null;

                /** @var Order $order */
                $order = Order::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'device_id' => null,
                    'staff_id' => null,
                    'customer_id' => $customer?->id,
                    'table_id' => null,
                    'order_type' => $orderType,
                    'status' => OrderStatus::Paid->value,
                    'source' => OrderSource::MainPos->value,
                    'plate_number' => null,
                    'subtotal' => number_format($subtotal, 3, '.', ''),
                    'discount_total' => '0.000',
                    'tax_total' => '0.000',
                    'grand_total' => number_format($grandTotal, 3, '.', ''),
                    'opened_at' => $openedAt,
                    'closed_at' => $openedAt->copy()->addMinutes(2),
                    'client_event_id' => 'seed_' . $i . '_' . Str::random(8),
                    'note' => null,
                ]);
                $orderCount++;

                foreach ($lineRows as $row) {
                    OrderItem::query()->create([
                        'order_id' => $order->id,
                        'product_id' => $row['product_id'],
                        'product_name_snapshot' => $row['name'],
                        'qty' => number_format($row['qty'], 3, '.', ''),
                        'unit_price_snapshot' => number_format($row['unit_price'], 3, '.', ''),
                        'line_discount' => '0.000',
                        'line_total' => number_format($row['line_total'], 3, '.', ''),
                        'recipe_snapshot_json' => null,
                        'status' => OrderItemStatus::Served->value,
                        'notes' => null,
                    ]);
                    $itemCount++;
                }

                $paymentCount += $this->writeTenders($order, $grandTotal, $openedAt);
            }
        });

        return [
            'orders' => $orderCount,
            'items' => $itemCount,
            'payments' => $paymentCount,
        ];
    }

    /**
     * Writes 1 or 2 Payment rows matching the order's
     * grand_total. SUM(amount) == grand_total exactly (no
     * float drift — all amounts quantised to baisas).
     *
     * Returns the number of payment rows written so the
     * outer loop can accumulate the count without instance
     * state (the class is final readonly).
     */
    private function writeTenders(Order $order, float $grandTotal, Carbon $capturedAt): int
    {
        $roll = mt_rand(0, 99);
        if ($roll < 40) {
            // 40% cash
            $this->writePayment($order, PaymentMethod::Cash, $grandTotal, $capturedAt);
            return 1;
        }
        if ($roll < 90) {
            // 50% card
            $this->writePayment($order, PaymentMethod::Card, $grandTotal, $capturedAt);
            return 1;
        }
        // 10% split: cash portion + card portion summing to total
        $cashPortionBaisas = (int) round($grandTotal * 1000 * 0.4); // 40% cash
        $cashPortion = $cashPortionBaisas / 1000;
        $cardPortion = $grandTotal - $cashPortion;
        // Both legs of a split go in as split_part so reports
        // distinguish them from pure-cash and pure-card buckets.
        $this->writePayment($order, PaymentMethod::SplitPart, $cashPortion, $capturedAt);
        $this->writePayment($order, PaymentMethod::SplitPart, $cardPortion, $capturedAt);
        return 2;
    }

    private function writePayment(Order $order, PaymentMethod $method, float $amount, Carbon $capturedAt): void
    {
        Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'method' => $method->value,
            'amount' => number_format($amount, 3, '.', ''),
            'change_given' => null,
            'softpos_reference' => null,
            'softpos_auth_code' => null,
            'status' => PaymentStatus::Success->value,
            'pending_reconciliation' => false,
            'captured_at' => $capturedAt,
        ]);
    }

    /**
     * Distribute orders across the window with a bias toward
     * lunch + dinner peaks. The blueprint Sales Report
     * §5.11.1 expects "peak hour" detection to surface
     * realistic numbers, so the demo seeder produces a
     * detectable peak.
     */
    private function randomOpenedAt(Carbon $endingAt, int $i, int $count): Carbon
    {
        // Spread evenly across a 30-day window ending at endingAt.
        $daysBack = (int) floor(($i / max(1, $count)) * 30);
        $base = $endingAt->copy()->subDays($daysBack);
        // Choose an hour weighted toward 12-14 + 19-21.
        $hour = $this->peakBiasedHour();
        $minute = mt_rand(0, 59);
        return $base->setTime($hour, $minute, mt_rand(0, 59));
    }

    private function peakBiasedHour(): int
    {
        // 35% chance of a lunch-peak hour (12-14),
        // 35% chance of a dinner-peak hour (19-21),
        // 30% spread across the rest of the open day (8-23).
        $roll = mt_rand(0, 99);
        if ($roll < 35) {
            return mt_rand(12, 14);
        }
        if ($roll < 70) {
            return mt_rand(19, 21);
        }
        return mt_rand(8, 23);
    }

    private function weightedOrderType(): string
    {
        // 60 quick / 20 dine_in / 15 to_go / 3 delivery / 2 car
        $roll = mt_rand(0, 99);
        return match (true) {
            $roll < 60 => OrderType::Quick->value,
            $roll < 80 => OrderType::DineIn->value,
            $roll < 95 => OrderType::ToGo->value,
            $roll < 98 => OrderType::Delivery->value,
            default => OrderType::Car->value,
        };
    }

    private function weightedItemsCount(): int
    {
        // 40% one item, 35% two, 15% three, 10% four.
        $roll = mt_rand(0, 99);
        return match (true) {
            $roll < 40 => 1,
            $roll < 75 => 2,
            $roll < 90 => 3,
            default => 4,
        };
    }

    private function shouldAttachCustomer(): bool
    {
        return mt_rand(0, 99) < 30;
    }
}
