<?php

declare(strict_types=1);

/**
 * Discounted & Comped Products report — the product-level unification of every
 * price reduction: offers, discounts (incl. loyalty redemptions, which fold
 * into the 'discount' type), manager comps, and per-item gifts.
 *
 * Covers: per-product + per-(product,type) attribution via order_item_id ->
 * pos_order_items.product_name_snapshot; the whole-order bucket for
 * order_item_id NULL applications; type reconciliation; the recent drill-down;
 * paid/window/tenant scope; and the export opt-in.
 */

use App\Models\Company;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Insert a discount-application row (offer_id set => offer; else => discount). */
function dcpDiscount(array $ctx, Order $order, ?int $itemId, ?int $offerId, ?int $discountId, string $name, string $amount, string $at = '2026-06-15 12:00:00'): void
{
    DB::table('pos_order_discounts')->insert([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id,
        'order_item_id' => $itemId, 'discount_id' => $discountId, 'offer_id' => $offerId,
        'name_snapshot' => $name, 'amount_type_snapshot' => null, 'amount' => $amount,
        'applied_at' => $at, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Insert a comp/gift row (is_gift true => gift; false => manager comp). */
function dcpComp(array $ctx, Order $order, ?int $itemId, bool $isGift, string $reasonName, string $amount, string $at = '2026-06-15 12:00:00'): void
{
    DB::table('pos_order_comps')->insert([
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'order_id' => $order->id,
        'order_item_id' => $itemId, 'comp_reason_id' => null,
        'reason_code_snapshot' => $isGift ? 'gift' : 'waste', 'reason_name_snapshot' => $reasonName,
        'is_gift' => $isGift, 'amount' => $amount, 'approved_by_pos_staff_id' => null,
        'note' => null, 'applied_at' => $at, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Build one paid order with a Cappuccino line (qty 2) + a Croissant line
 * (qty 3), then apply: an OFFER + a manager COMP on the cappuccino, a loyalty
 * DISCOUNT + a GIFT on the croissant, and a whole-order DISCOUNT.
 *
 * @return array{ctx: array, capp: Product, cro: Product}
 */
function dcpSeed(): array
{
    $ctx = makeMerchantActor();
    $capp = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Cappuccino']);
    $cro = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Croissant']);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create(['opened_at' => '2026-06-15 12:00:00']);
    $cappLine = OrderItem::factory()->for($order, 'order')->for($capp, 'product')->create(['product_name_snapshot' => 'Cappuccino', 'qty' => '2.000']);
    $croLine = OrderItem::factory()->for($order, 'order')->for($cro, 'product')->create(['product_name_snapshot' => 'Croissant', 'qty' => '3.000']);

    $offer = Offer::factory()->for($ctx['company'], 'company')->create(['name' => 'BOGO']);

    dcpDiscount($ctx, $order, $cappLine->id, $offer->id, null, 'BOGO', '2.000');                  // offer on cappuccino
    dcpDiscount($ctx, $order, $croLine->id, null, null, 'Loyalty redemption', '1.000');            // loyalty -> discount, on croissant
    dcpDiscount($ctx, $order, null, null, null, 'OMR1 off', '0.500');                              // whole-order discount
    dcpComp($ctx, $order, $cappLine->id, false, 'Wastage', '1.500');                                // comp on cappuccino
    dcpComp($ctx, $order, $croLine->id, true, 'Gift', '3.000');                                     // gift on croissant

    return ['ctx' => $ctx, 'capp' => $capp, 'cro' => $cro];
}

function dcpGet(): array
{
    return test()->getJson('/api/reports/discounted-comped-products?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()->json('data');
}

it('attributes each price reduction to the exact product and type', function (): void {
    dcpSeed();
    $data = dcpGet();

    $find = fn (array $rows, string $product, string $type): ?array => collect($rows)
        ->first(fn ($r): bool => $r['product_name'] === $product && $r['type'] === $type);

    expect($find($data['by_product_and_type'], 'Cappuccino', 'offer')['total_off'])->toBe('2.000');
    expect($find($data['by_product_and_type'], 'Croissant', 'discount')['total_off'])->toBe('1.000');
    expect($find($data['by_product_and_type'], 'Cappuccino', 'comp')['total_off'])->toBe('1.500');
    expect($find($data['by_product_and_type'], 'Croissant', 'gift')['total_off'])->toBe('3.000');
    // The loyalty-redemption line is folded into 'discount' (chosen behaviour).
    expect($find($data['by_product_and_type'], 'Croissant', 'discount')['units'])->toBe('3.000');
});

it('collapses all mechanisms into one row per product', function (): void {
    dcpSeed();
    $data = dcpGet();

    $capp = collect($data['by_product'])->firstWhere('product_name', 'Cappuccino');
    $cro = collect($data['by_product'])->firstWhere('product_name', 'Croissant');
    // Cappuccino: 2 (offer) + 1.5 (comp); Croissant: 1 (loyalty) + 3 (gift).
    expect($capp['total_off'])->toBe('3.500');
    expect($capp['times'])->toBe(2);
    expect($cro['total_off'])->toBe('4.000');
    expect($cro['times'])->toBe(2);
});

it('totals by type and reconciles to the grand total', function (): void {
    dcpSeed();
    $data = dcpGet();

    $byType = collect($data['by_type'])->keyBy('type');
    expect($byType['offer']['total_off'])->toBe('2.000');
    // discount = loyalty line (1) + whole-order discount (0.5).
    expect($byType['discount']['total_off'])->toBe('1.500');
    expect($byType['comp']['total_off'])->toBe('1.500');
    expect($byType['gift']['total_off'])->toBe('3.000');

    // Grand total = product-level (7.5) + whole-order (0.5) = 8.
    expect($data['headline']['total_taken_off'])->toBe('8.000');
    expect($data['headline']['product_level_total'])->toBe('7.500');
    expect($data['headline']['whole_order_total'])->toBe('0.500');
    expect($data['headline']['distinct_products'])->toBe(2);
    expect($data['headline']['application_count'])->toBe(5);
});

it('keeps whole-order applications in their own bucket, not attributed to a product', function (): void {
    dcpSeed();
    $data = dcpGet();

    expect($data['whole_order'])->toHaveCount(1);
    expect($data['whole_order'][0]['type'])->toBe('discount');
    expect($data['whole_order'][0]['total_off'])->toBe('0.500');
    // The order-level discount must NOT appear as a product row.
    expect(collect($data['by_product'])->pluck('product_name')->all())->not->toContain('OMR1 off');
});

it('lists recent applications with product, type and name', function (): void {
    dcpSeed();
    $data = dcpGet();

    expect($data['recent'])->toHaveCount(5);
    $loyalty = collect($data['recent'])->firstWhere('name', 'Loyalty redemption');
    expect($loyalty['type'])->toBe('discount');
    expect($loyalty['product_name'])->toBe('Croissant');
    // The whole-order discount appears with a null product.
    $orderLevel = collect($data['recent'])->firstWhere('name', 'OMR1 off');
    expect($orderLevel['product_name'])->toBeNull();
});

it('counts a line quantity once even when it gets two same-type applications', function (): void {
    $ctx = makeMerchantActor();
    $latte = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create(['opened_at' => '2026-06-15 12:00:00']);
    $line = OrderItem::factory()->for($order, 'order')->for($latte, 'product')->create(['product_name_snapshot' => 'Latte', 'qty' => '2.000']);
    // Two 'discount' rows on the SAME line (e.g. an auto + a manual discount).
    dcpDiscount($ctx, $order, $line->id, null, null, 'Auto 10%', '1.000');
    dcpDiscount($ctx, $order, $line->id, null, null, 'Manager 0.5', '0.500');

    $data = dcpGet();

    $row = collect($data['by_product_and_type'])->first(fn ($r): bool => $r['product_name'] === 'Latte' && $r['type'] === 'discount');
    // Units = the line's 2 (NOT 4 from the fan-out); money sums both; 2 applications.
    expect($row['units'])->toBe('2.000');
    expect($row['total_off'])->toBe('1.500');
    expect($row['times'])->toBe(2);
});

it('does not count unpaid or out-of-window applications', function (): void {
    $ctx = makeMerchantActor();
    $p = Product::factory()->for($ctx['company'], 'company')->create(['name' => 'Latte']);
    $stale = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create(['opened_at' => '2026-05-15 12:00:00']);
    $open = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['status' => 'open', 'opened_at' => '2026-06-15 12:00:00']);
    $staleLine = OrderItem::factory()->for($stale, 'order')->for($p, 'product')->create(['product_name_snapshot' => 'Latte']);
    $openLine = OrderItem::factory()->for($open, 'order')->for($p, 'product')->create(['product_name_snapshot' => 'Latte']);
    dcpDiscount($ctx, $stale, $staleLine->id, null, null, 'Old', '9.000', '2026-05-15 12:00:00');
    dcpComp($ctx, $open, $openLine->id, false, 'Wastage', '4.000');

    $data = dcpGet();
    expect($data['by_product'])->toBe([]);
    expect($data['headline']['total_taken_off'])->toBe('0.000');
});

it('does not leak another company data', function (): void {
    dcpSeed();

    $other = Company::factory()->create();
    $otherBranch = \App\Models\Branch::factory()->for($other, 'company')->create();
    $otherProduct = Product::factory()->for($other, 'company')->create(['name' => 'Secret']);
    $otherOrder = Order::factory()->for($other, 'company')->for($otherBranch, 'branch')->paid()->create(['opened_at' => '2026-06-15 12:00:00']);
    $otherLine = OrderItem::factory()->for($otherOrder, 'order')->for($otherProduct, 'product')->create(['product_name_snapshot' => 'Secret']);
    DB::table('pos_order_comps')->insert([
        'company_id' => $other->id, 'branch_id' => $otherBranch->id, 'order_id' => $otherOrder->id,
        'order_item_id' => $otherLine->id, 'comp_reason_id' => null, 'reason_code_snapshot' => 'waste',
        'reason_name_snapshot' => 'Wastage', 'is_gift' => false, 'amount' => '500.000',
        'approved_by_pos_staff_id' => null, 'note' => null, 'applied_at' => '2026-06-15 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = dcpGet();
    expect($data['headline']['total_taken_off'])->toBe('8.000');
    expect(collect($data['by_product'])->pluck('product_name')->all())->not->toContain('Secret');
});

it('is exportable (registered in the export map)', function (): void {
    dcpSeed();

    test()->getJson('/api/reports/discounted-comped-products/export?format=csv&date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
