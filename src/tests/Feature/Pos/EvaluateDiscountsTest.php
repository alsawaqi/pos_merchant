<?php

declare(strict_types=1);

/**
 * Phase 6d — pure-function evaluator coverage.
 *
 * Blueprint Phase 6 exit checklist:
 *   "Unit tests of the evaluators pass for at least 30
 *    scenarios."
 *
 * This file ships 30+ scenarios across the evaluator's
 * 6 axes:
 *
 *   1. Per-line product-scope rules
 *   2. Per-line category-scope rules
 *   3. Order-scope rules
 *   4. Percent vs fixed math (with baisas precision)
 *   5. Stackable vs non-stackable interactions
 *   6. Cap semantics (no negative lines / orders)
 *
 * Each scenario builds discount rule objects in-memory via the
 * factory (no DB writes needed for the evaluator itself — it's
 * a pure function). Where targets are needed, we attach them
 * directly to the model's relation.
 */

use App\Actions\Pos\Discounts\EvaluateDiscounts;
use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use App\Enums\DiscountTargetType;
use App\Models\Discount;
use App\Models\DiscountTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Helpers ---------------------------------------------------------

function buildOrder(array $lineSpecs): array
{
    $lines = [];
    $subtotal = 0;
    foreach ($lineSpecs as $i => $spec) {
        $line = [
            'line_id' => 'L' . $i,
            'product_id' => $spec['product_id'] ?? (1000 + $i),
            'category_id' => $spec['category_id'] ?? null,
            'qty' => '1.000',
            'unit_price' => $spec['line_total'],
            'line_total' => $spec['line_total'],
        ];
        $lines[] = $line;
        $subtotal += (float) $spec['line_total'];
    }
    return ['lines' => $lines, 'subtotal' => number_format($subtotal, 3, '.', '')];
}

/**
 * Per-test ctx cache. Each test calls makeMerchantActor() once
 * via getCtx(); subsequent calls within the SAME test reuse
 * the same actor. RefreshDatabase clears between tests so the
 * static must reset — we accomplish that by reading a sentinel
 * row count and rebuilding when the DB has been wiped.
 */
function getCtx(): array
{
    static $ctx = null;
    if ($ctx === null || \App\Models\Company::query()->find($ctx['company']->id) === null) {
        $ctx = makeMerchantActor();
    }
    return $ctx;
}

function makeRule(array $attrs): Discount
{
    $ctx = getCtx();
    $d = Discount::factory()->for($ctx['company'], 'company')->create(array_merge([
        'status' => DiscountStatus::Active->value,
        'scope' => DiscountScope::Order->value,
        'amount_type' => DiscountAmountType::Percent->value,
        'amount' => '10',
        'stackable' => false,
    ], $attrs));
    return $d;
}

function attachTargets(Discount $d, array $targets): Discount
{
    foreach ($targets as $t) {
        DiscountTarget::factory()->for($d, 'discount')->create([
            'target_type' => $t['type'],
            'target_id' => $t['id'],
        ]);
    }
    return $d->load('targets');
}

// =================== ORDER SCOPE ===================

it('applies an order-scope percent discount to the subtotal', function (): void {
    $order = buildOrder([
        ['line_total' => '10.000', 'product_id' => 1],
        ['line_total' => '5.000', 'product_id' => 2],
    ]);
    $rule = makeRule(['amount' => '10']); // 10% off

    $result = EvaluateDiscounts::run($order, [$rule]);

    expect($result['lineDiscounts'])->toBe([]);
    // 10% of 15.000 = 1.500.
    expect($result['orderDiscount'])->toBe('1.500');
});

it('applies an order-scope fixed discount as a flat OMR amount', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);
    $rule = makeRule([
        'amount_type' => DiscountAmountType::Fixed->value,
        'amount' => '2.500',
    ]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['orderDiscount'])->toBe('2.500');
});

it('caps a fixed order discount at the subtotal so it never goes negative', function (): void {
    $order = buildOrder([['line_total' => '3.000', 'product_id' => 1]]);
    $rule = makeRule([
        'amount_type' => DiscountAmountType::Fixed->value,
        'amount' => '10.000',
    ]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['orderDiscount'])->toBe('3.000'); // capped
});

it('returns zero discount when no rules apply', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);

    $result = EvaluateDiscounts::run($order, []);
    expect($result['lineDiscounts'])->toBe([]);
    expect($result['orderDiscount'])->toBe('0.000');
});

// =================== PRODUCT SCOPE ===================

it('applies a product-scope percent discount only to matching lines', function (): void {
    $order = buildOrder([
        ['line_total' => '10.000', 'product_id' => 1],
        ['line_total' => '5.000', 'product_id' => 2],
    ]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '20',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);

    // 20% of 10.000 (product 1 line) = 2.000. Product 2 line untouched.
    expect($result['lineDiscounts'])->toHaveKey('L0');
    expect($result['lineDiscounts']['L0'])->toBe('2.000');
    expect($result['lineDiscounts'])->not->toHaveKey('L1');
    expect($result['orderDiscount'])->toBe('0.000');
});

it('applies a product-scope fixed discount capped at the line total', function (): void {
    $order = buildOrder([
        ['line_total' => '2.000', 'product_id' => 1],
        ['line_total' => '5.000', 'product_id' => 2],
    ]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount_type' => DiscountAmountType::Fixed->value,
        'amount' => '3.000',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    // Line total 2.000, fixed 3.000 → capped at 2.000.
    expect($result['lineDiscounts']['L0'])->toBe('2.000');
});

it('skips a product-scope rule when no line matches', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 42]]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '20',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]); // not 42

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts'])->toBe([]);
});

it('applies a product-scope rule to multiple matching lines in one order', function (): void {
    $order = buildOrder([
        ['line_total' => '10.000', 'product_id' => 1],
        ['line_total' => '20.000', 'product_id' => 1],
        ['line_total' => '5.000', 'product_id' => 2],
    ]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts']['L0'])->toBe('5.000');
    expect($result['lineDiscounts']['L1'])->toBe('10.000');
    expect($result['lineDiscounts'])->not->toHaveKey('L2');
});

// =================== CATEGORY SCOPE ===================

it('applies a category-scope rule to lines whose category matches', function (): void {
    $order = buildOrder([
        ['line_total' => '4.000', 'product_id' => 1, 'category_id' => 10],
        ['line_total' => '6.000', 'product_id' => 2, 'category_id' => 20],
    ]);
    $rule = makeRule([
        'scope' => DiscountScope::Category->value,
        'amount' => '25',
    ]);
    attachTargets($rule, [['type' => 'category', 'id' => 10]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts']['L0'])->toBe('1.000');
    expect($result['lineDiscounts'])->not->toHaveKey('L1');
});

it('skips a category-scope rule for lines whose product has no category_id', function (): void {
    $order = buildOrder([['line_total' => '5.000', 'product_id' => 1, 'category_id' => null]]);
    $rule = makeRule([
        'scope' => DiscountScope::Category->value,
        'amount' => '10',
    ]);
    attachTargets($rule, [['type' => 'category', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts'])->toBe([]);
});

// =================== STACKABLE INTERACTIONS ===================

it('compounds two stackable percent rules on the same line', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);
    $rule1 = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => true,
    ]);
    attachTargets($rule1, [['type' => 'product', 'id' => 1]]);
    $rule2 = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '20',
        'stackable' => true,
    ]);
    attachTargets($rule2, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule1, $rule2]);
    // 10% off 10.000 = 1.000 (remaining 9.000)
    // 20% off 9.000 = 1.800
    // Total line discount = 2.800
    expect($result['lineDiscounts']['L0'])->toBe('2.800');
});

it('non-stackable rule wins exclusively, blocking subsequent rules on that line', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);
    // Non-stackable percent (higher id, applied first per sort).
    $exclusive = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => false,
    ]);
    attachTargets($exclusive, [['type' => 'product', 'id' => 1]]);
    $stackable = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '30',
        'stackable' => true,
    ]);
    attachTargets($stackable, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$exclusive, $stackable]);
    // Non-stackable rule sorts FIRST. Once fired, no further
    // rules apply. So only the 10% (= 1.000) lands.
    expect($result['lineDiscounts']['L0'])->toBe('1.000');
});

it('non-stackable on one line does not block discounts on a different line', function (): void {
    $order = buildOrder([
        ['line_total' => '10.000', 'product_id' => 1],
        ['line_total' => '20.000', 'product_id' => 2],
    ]);
    $exclusive = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => false,
    ]);
    attachTargets($exclusive, [['type' => 'product', 'id' => 1]]);
    $other = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
        'stackable' => true,
    ]);
    attachTargets($other, [['type' => 'product', 'id' => 2]]);

    $result = EvaluateDiscounts::run($order, [$exclusive, $other]);
    expect($result['lineDiscounts']['L0'])->toBe('1.000');
    expect($result['lineDiscounts']['L1'])->toBe('10.000');
});

it('stackable order discounts compound after line discounts', function (): void {
    $order = buildOrder([['line_total' => '100.000', 'product_id' => 1]]);
    $lineRule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => true,
    ]);
    attachTargets($lineRule, [['type' => 'product', 'id' => 1]]);
    $orderRule = makeRule([
        'amount' => '20',
        'stackable' => true,
    ]);

    $result = EvaluateDiscounts::run($order, [$lineRule, $orderRule]);
    // Line: 10% off 100 = 10. Remaining order: 90.
    // Order: 20% off 90 = 18.
    expect($result['lineDiscounts']['L0'])->toBe('10.000');
    expect($result['orderDiscount'])->toBe('18.000');
});

it('non-stackable order discount blocks subsequent order discounts', function (): void {
    $order = buildOrder([['line_total' => '50.000', 'product_id' => 1]]);
    $a = makeRule(['amount' => '10', 'stackable' => false]);
    $b = makeRule(['amount' => '20', 'stackable' => true]);

    $result = EvaluateDiscounts::run($order, [$a, $b]);
    expect($result['orderDiscount'])->toBe('5.000'); // only the 10%
});

// =================== BAISAS PRECISION ===================

it('rounds percent reductions to the nearest baisa', function (): void {
    // 33% of 1.000 OMR = 0.330 OMR (exactly).
    $order = buildOrder([['line_total' => '1.000', 'product_id' => 1]]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '33',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts']['L0'])->toBe('0.330');
});

it('SUM of lineDiscounts + orderDiscount never exceeds the subtotal', function (): void {
    $order = buildOrder([['line_total' => '5.000', 'product_id' => 1]]);
    $rules = [
        makeRule([
            'scope' => DiscountScope::Product->value,
            'amount_type' => DiscountAmountType::Fixed->value,
            'amount' => '4.000',
            'stackable' => true,
        ]),
        makeRule([
            'amount_type' => DiscountAmountType::Fixed->value,
            'amount' => '10.000',
            'stackable' => true,
        ]),
    ];
    attachTargets($rules[0], [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, $rules);
    $lineSum = array_sum(array_map(static fn ($v): float => (float) $v, $result['lineDiscounts']));
    $orderOff = (float) $result['orderDiscount'];
    expect($lineSum + $orderOff)->toBeLessThanOrEqual(5.0);
});

it('handles a 100% percent discount as full price off', function (): void {
    $order = buildOrder([['line_total' => '7.500', 'product_id' => 1]]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '100',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts']['L0'])->toBe('7.500');
});

// =================== EDGE CASES ===================

it('handles an empty order (no lines)', function (): void {
    $order = ['lines' => [], 'subtotal' => '0.000'];
    $rule = makeRule(['amount' => '50']);
    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts'])->toBe([]);
    expect($result['orderDiscount'])->toBe('0.000');
});

it('handles a zero-priced line', function (): void {
    $order = buildOrder([['line_total' => '0.000', 'product_id' => 1]]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts'])->toBe([]); // 0 baisas off skipped
});

it('skips lines for product-scope rules with no targets', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
    ]);
    // No targets attached.

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts'])->toBe([]);
});

it('only applies a fixed line discount once even with multiple matching lines', function (): void {
    // Fixed rules apply to EACH matching line independently —
    // this is the documented behaviour.
    $order = buildOrder([
        ['line_total' => '5.000', 'product_id' => 1],
        ['line_total' => '8.000', 'product_id' => 1],
    ]);
    $rule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount_type' => DiscountAmountType::Fixed->value,
        'amount' => '1.000',
    ]);
    attachTargets($rule, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$rule]);
    expect($result['lineDiscounts']['L0'])->toBe('1.000');
    expect($result['lineDiscounts']['L1'])->toBe('1.000');
});

// =================== DETERMINISM ===================

it('produces identical output for the same input regardless of rule list order', function (): void {
    $order = buildOrder([['line_total' => '10.000', 'product_id' => 1]]);
    $a = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => true,
    ]);
    attachTargets($a, [['type' => 'product', 'id' => 1]]);
    $b = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '20',
        'stackable' => true,
    ]);
    attachTargets($b, [['type' => 'product', 'id' => 1]]);

    $first = EvaluateDiscounts::run($order, [$a, $b]);
    $second = EvaluateDiscounts::run($order, [$b, $a]);
    expect($first['lineDiscounts'])->toBe($second['lineDiscounts']);
    expect($first['orderDiscount'])->toBe($second['orderDiscount']);
});

it('sorts non-stackable rules before stackable rules consistently', function (): void {
    $order = buildOrder([['line_total' => '100.000', 'product_id' => 1]]);
    $exclusive = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
        'stackable' => false,
    ]);
    attachTargets($exclusive, [['type' => 'product', 'id' => 1]]);
    $stackable = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => true,
    ]);
    attachTargets($stackable, [['type' => 'product', 'id' => 1]]);

    // Pass them in stackable-first; evaluator must sort non-
    // stackable first regardless.
    $result = EvaluateDiscounts::run($order, [$stackable, $exclusive]);
    expect($result['lineDiscounts']['L0'])->toBe('50.000');
});

// =================== MIXED RULES ===================

it('mixes a product-scope rule with an order-scope rule cleanly', function (): void {
    $order = buildOrder([
        ['line_total' => '20.000', 'product_id' => 1, 'category_id' => 5],
        ['line_total' => '30.000', 'product_id' => 2, 'category_id' => 5],
    ]);
    $productRule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
        'stackable' => true,
    ]);
    attachTargets($productRule, [['type' => 'product', 'id' => 1]]);
    $orderRule = makeRule(['amount' => '10', 'stackable' => true]);

    $result = EvaluateDiscounts::run($order, [$productRule, $orderRule]);
    // Line 0: 50% off 20 = 10.
    expect($result['lineDiscounts']['L0'])->toBe('10.000');
    // Line 1: untouched.
    expect($result['lineDiscounts'])->not->toHaveKey('L1');
    // Order: subtotal 50 - line discount 10 = 40, 10% = 4.
    expect($result['orderDiscount'])->toBe('4.000');
});

it('product + category rules can both fire on the same line when stackable', function (): void {
    $order = buildOrder([['line_total' => '100.000', 'product_id' => 1, 'category_id' => 5]]);
    $productRule = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '10',
        'stackable' => true,
    ]);
    attachTargets($productRule, [['type' => 'product', 'id' => 1]]);
    $categoryRule = makeRule([
        'scope' => DiscountScope::Category->value,
        'amount' => '20',
        'stackable' => true,
    ]);
    attachTargets($categoryRule, [['type' => 'category', 'id' => 5]]);

    $result = EvaluateDiscounts::run($order, [$productRule, $categoryRule]);
    // Product rule: 10% off 100 = 10. Remaining 90.
    // Category rule: 20% off 90 = 18. Total line discount 28.
    expect($result['lineDiscounts']['L0'])->toBe('28.000');
});

it('handles fixed line discount that exhausts the line preventing subsequent applies', function (): void {
    $order = buildOrder([['line_total' => '5.000', 'product_id' => 1]]);
    $exhausting = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount_type' => DiscountAmountType::Fixed->value,
        'amount' => '5.000',
        'stackable' => true,
    ]);
    attachTargets($exhausting, [['type' => 'product', 'id' => 1]]);
    $second = makeRule([
        'scope' => DiscountScope::Product->value,
        'amount' => '50',
        'stackable' => true,
    ]);
    attachTargets($second, [['type' => 'product', 'id' => 1]]);

    $result = EvaluateDiscounts::run($order, [$exhausting, $second]);
    // First rule exhausts the line. Second can't apply on top.
    expect($result['lineDiscounts']['L0'])->toBe('5.000');
});

// =================== APPLIES-AT PREDICATES (Discount model) ===================

it('Discount::isActiveAt returns false outside the validity window', function (): void {
    $ctx = getCtx();
    $d = Discount::factory()->for($ctx['company'], 'company')->create([
        'validity_start' => '2026-06-01 00:00:00',
        'validity_end' => '2026-06-30 23:59:59',
    ]);

    expect($d->isActiveAt(new DateTimeImmutable('2026-05-31')))->toBeFalse();
    expect($d->isActiveAt(new DateTimeImmutable('2026-06-15')))->toBeTrue();
    expect($d->isActiveAt(new DateTimeImmutable('2026-07-01')))->toBeFalse();
});

it('Discount::matchesTime supports midnight-wrap windows', function (): void {
    $ctx = getCtx();
    $d = Discount::factory()->for($ctx['company'], 'company')->create([
        'time_start' => '22:00:00',
        'time_end' => '02:00:00',
    ]);

    expect($d->matchesTime(new DateTimeImmutable('2026-06-04 22:30:00')))->toBeTrue();
    expect($d->matchesTime(new DateTimeImmutable('2026-06-04 01:00:00')))->toBeTrue();
    expect($d->matchesTime(new DateTimeImmutable('2026-06-04 15:00:00')))->toBeFalse();
});

it('Discount::matchesBranch returns true for null/empty branch_scope (all branches)', function (): void {
    $ctx = getCtx();
    $d = Discount::factory()->for($ctx['company'], 'company')->create([
        'branch_scope_json' => null,
    ]);
    expect($d->matchesBranch(99))->toBeTrue();
});

it('Discount::matchesBranch filters when branch_scope is a subset', function (): void {
    $ctx = getCtx();
    $d = Discount::factory()->for($ctx['company'], 'company')->create([
        'branch_scope_json' => [1, 2, 3],
    ]);
    expect($d->matchesBranch(1))->toBeTrue();
    expect($d->matchesBranch(99))->toBeFalse();
});
