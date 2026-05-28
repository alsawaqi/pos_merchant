<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountTargetType;
use App\Models\Discount;

/**
 * Phase 6d — pure-function discount evaluator (blueprint
 * §13 Phase 6 exit checklist).
 *
 * "Both evaluators are shared between server and Flutter
 *  device (via a small ported library) so offline and online
 *  produce identical results."
 *
 * This class is INTENTIONALLY a static-only utility — no
 * constructor injection, no DB access, no clock dependency.
 * The caller resolves applicable rules (using
 * Discount::appliesAt()) and passes them in alongside the
 * order shape. The evaluator does pure money math.
 *
 * Input shape:
 *
 *   $order = [
 *       'lines' => [
 *           [
 *               'line_id'      => string  (anything stable; used as
 *                                          the key in the lineDiscounts
 *                                          return map),
 *               'product_id'   => int,
 *               'category_id'  => int|null,
 *               'qty'          => string  (decimal:3 OMR-style),
 *               'unit_price'   => string  (decimal:3),
 *               'line_total'   => string  (decimal:3, = qty * unit_price
 *                                          BEFORE this evaluator's
 *                                          discounts apply),
 *           ],
 *           ...
 *       ],
 *       'subtotal' => string  (sum of line_totals; decimal:3),
 *   ];
 *
 *   $rules = list<Discount>  (already filtered by appliesAt())
 *
 * Output shape:
 *
 *   [
 *       'lineDiscounts' => [
 *           '<line_id>' => string  (decimal:3 OMR off this line),
 *           ...
 *       ],
 *       'orderDiscount' => string  (decimal:3 OMR off the order total
 *                                    AFTER line discounts applied),
 *   ];
 *
 * SEMANTICS:
 *
 *   1. Sort rules: NON-STACKABLE FIRST (within product/category/order
 *      scope, the non-stackable rule wins; subsequent stackable
 *      rules apply on top).
 *
 *   2. For each line, walk the applicable rules in order:
 *      - If rule is product scope, line.product_id must be in
 *        rule.targets where type=product
 *      - If rule is category scope, line.category_id must be
 *        in rule.targets where type=category
 *      - If a non-stackable rule has already fired on this
 *        line, SKIP further rules for that line (intent: the
 *        merchant marked this exclusive)
 *      - Apply: percent rules multiply against the CURRENT
 *        line subtotal (so stacked percents compound naturally);
 *        fixed rules subtract a flat OMR amount (capped at the
 *        remaining line subtotal so the line can't go negative)
 *
 *   3. For order-scope rules: applied to the order subtotal
 *      AFTER line discounts. Same non-stackable-first logic.
 *      Capped so orderDiscount + sum(lineDiscounts) ≤ subtotal.
 *
 * Money math is done in INT BAISAS to avoid float drift. All
 * inputs and outputs are decimal:3 strings; the evaluator
 * converts to int baisas at the boundary, computes, then
 * converts back via number_format(x/1000, 3, '.', '').
 */
final class EvaluateDiscounts
{
    /**
     * @param  array{lines: list<array{line_id: string, product_id: int, category_id: int|null, qty: string, unit_price: string, line_total: string}>, subtotal: string}  $order
     * @param  list<Discount>  $rules
     * @return array{lineDiscounts: array<string, string>, orderDiscount: string}
     */
    public static function run(array $order, array $rules): array
    {
        // Stable sort: non-stackable first, then by id (Discount
        // model id, ascending). Stability matters so the same
        // input on server + device produces the same output.
        $sorted = $rules;
        usort($sorted, static function (Discount $a, Discount $b): int {
            $aStack = $a->stackable ? 1 : 0;
            $bStack = $b->stackable ? 1 : 0;
            if ($aStack !== $bStack) {
                return $aStack <=> $bStack;
            }
            return ((int) $a->id) <=> ((int) $b->id);
        });

        // Pre-compute target sets per rule for O(1) lookups.
        $targets = self::buildTargetSets($sorted);

        // Pass 1: per-line discounts.
        $lineDiscounts = [];
        // Track which lines have been touched by a NON-STACKABLE
        // rule so subsequent rules know to skip them.
        $linesNonStackableHit = [];

        foreach ($order['lines'] as $line) {
            $lineId = (string) $line['line_id'];
            $baisas = self::toBaisas($line['line_total']);
            $remaining = $baisas;
            $discountBaisas = 0;

            foreach ($sorted as $rule) {
                // Order-scope rules handled in pass 2.
                if ($rule->scope === DiscountScope::Order) {
                    continue;
                }

                // Product / category scope: line must match.
                if (! self::lineMatches($line, $rule, $targets)) {
                    continue;
                }

                // A non-stackable rule already hit this line —
                // skip everything else. "non-stackable" means
                // EXCLUSIVE; the merchant's intent is "this is
                // the only one".
                if (isset($linesNonStackableHit[$lineId])) {
                    continue;
                }

                $off = self::applyRule($remaining, $rule);
                if ($off <= 0) {
                    continue;
                }
                $discountBaisas += $off;
                $remaining -= $off;
                if (! $rule->stackable) {
                    $linesNonStackableHit[$lineId] = true;
                }
                if ($remaining <= 0) {
                    break;
                }
            }

            if ($discountBaisas > 0) {
                $lineDiscounts[$lineId] = self::fromBaisas($discountBaisas);
            }
        }

        // Pass 2: order-scope rules. Compute the remaining
        // subtotal (subtotal - sum line discounts) and apply.
        $subtotalBaisas = self::toBaisas($order['subtotal']);
        $lineDiscountBaisas = array_sum(array_map(self::toBaisas(...), $lineDiscounts));
        $remainingOrderBaisas = $subtotalBaisas - $lineDiscountBaisas;
        $orderDiscountBaisas = 0;
        $orderNonStackableHit = false;

        foreach ($sorted as $rule) {
            if ($rule->scope !== DiscountScope::Order) {
                continue;
            }
            if ($orderNonStackableHit) {
                continue;
            }
            $off = self::applyRule($remainingOrderBaisas, $rule);
            if ($off <= 0) {
                continue;
            }
            $orderDiscountBaisas += $off;
            $remainingOrderBaisas -= $off;
            if (! $rule->stackable) {
                $orderNonStackableHit = true;
            }
            if ($remainingOrderBaisas <= 0) {
                break;
            }
        }

        return [
            'lineDiscounts' => $lineDiscounts,
            'orderDiscount' => self::fromBaisas($orderDiscountBaisas),
        ];
    }

    /**
     * Build per-rule target sets keyed by type for O(1) lookup.
     *
     * @param  list<Discount>  $rules
     * @return array<int, array{product: array<int, true>, category: array<int, true>}>
     */
    private static function buildTargetSets(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $byType = ['product' => [], 'category' => []];
            // The targets relation may or may not be eager-loaded.
            // The evaluator works either way; the caller is
            // expected to eager-load for performance.
            foreach ($rule->targets as $target) {
                $byType[$target->target_type->value][(int) $target->target_id] = true;
            }
            $out[(int) $rule->id] = $byType;
        }
        return $out;
    }

    /**
     * @param  array{product_id: int, category_id: int|null}  $line
     * @param  array<int, array{product: array<int, true>, category: array<int, true>}>  $targets
     */
    private static function lineMatches(array $line, Discount $rule, array $targets): bool
    {
        $ruleTargets = $targets[(int) $rule->id] ?? null;
        if ($ruleTargets === null) {
            return false;
        }
        if ($rule->scope === DiscountScope::Product) {
            return isset($ruleTargets['product'][(int) $line['product_id']]);
        }
        if ($rule->scope === DiscountScope::Category) {
            $catId = $line['category_id'];
            return $catId !== null && isset($ruleTargets['category'][(int) $catId]);
        }
        return false;
    }

    /**
     * Apply a rule to a remaining-baisas value. Returns the
     * baisas reduction (always non-negative).
     */
    private static function applyRule(int $remainingBaisas, Discount $rule): int
    {
        if ($remainingBaisas <= 0) {
            return 0;
        }
        $amount = (float) $rule->amount;
        if ($rule->amount_type === DiscountAmountType::Percent) {
            // percent of baisas, rounded to nearest baisa.
            // Cap at the remaining value (never let a line
            // go negative).
            $off = (int) round($remainingBaisas * ($amount / 100));
            return min($off, $remainingBaisas);
        }
        // Fixed: convert OMR to baisas.
        $offBaisas = (int) round($amount * 1000);
        return min($offBaisas, $remainingBaisas);
    }

    private static function toBaisas(string $omr): int
    {
        // Use number_format then strip the dot so "2.500" → 2500.
        return (int) round(((float) $omr) * 1000);
    }

    private static function fromBaisas(int $baisas): string
    {
        return number_format($baisas / 1000, 3, '.', '');
    }
}
