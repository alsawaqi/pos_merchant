<?php

declare(strict_types=1);

namespace App\Actions\Pos\Loyalty;

use App\Enums\LoyaltyRuleType;
use App\Models\LoyaltyRule;

/**
 * Loyalty refactor — pure-function loyalty evaluator (blueprint
 * §13: "evaluateLoyalty(order, rule, customer) → { stampsEarned,
 * pointsEarned, eligibleRedemptions[] }", shared between server +
 * Flutter device so offline and online produce identical results).
 *
 * Like EvaluateDiscounts, this is a static-only utility — no DB,
 * no clock, no injection. The caller resolves the applicable rule
 * (LoyaltyRule::isActiveAt() + the §5.8 restrictions) and passes
 * the order shape + the customer's current account state for that
 * rule. The function computes what the order WOULD earn and which
 * redemptions the (post-earn) balance unlocks. It does NOT mutate
 * anything — Phase 8's sale pipeline turns the result into
 * loyalty_transactions.
 *
 * Input:
 *   $order = ['subtotal' => string]   // decimal:3 OMR (post-discount)
 *   $rule  = LoyaltyRule              // type + config_json
 *   $account = ['stamp_count' => int, 'point_balance' => int]
 *              // the customer's CURRENT balances under this rule
 *              // (zeros for a brand-new account)
 *
 * config_json (visit_based): min_order_value, stamps_required,
 *   reward_type, reward_value, reward_product_id
 * config_json (spend_based): points_per_omr, redemption_points,
 *   redemption_value, min_redemption_points
 *
 * Output:
 *   [
 *     'stampsEarned' => int,
 *     'pointsEarned' => int,
 *     'eligibleRedemptions' => list<array<string, mixed>>,
 *   ]
 *
 * All money math is done in INT BAISAS to avoid float drift.
 */
final class EvaluateLoyalty
{
    /**
     * @param  array{subtotal: string}  $order
     * @param  array{stamp_count?: int, point_balance?: int}  $account
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    public static function run(array $order, LoyaltyRule $rule, array $account = []): array
    {
        $config = is_array($rule->config_json) ? $rule->config_json : [];
        $subtotalBaisas = self::toBaisas((string) ($order['subtotal'] ?? '0'));
        $stampCount = (int) ($account['stamp_count'] ?? 0);
        $pointBalance = (int) ($account['point_balance'] ?? 0);

        return match ($rule->type) {
            LoyaltyRuleType::VisitBased => self::evaluateVisit($config, $subtotalBaisas, $stampCount),
            LoyaltyRuleType::SpendBased => self::evaluateSpend($config, $subtotalBaisas, $pointBalance),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    private static function evaluateVisit(array $config, int $subtotalBaisas, int $stampCount): array
    {
        $minOrderBaisas = self::toBaisas((string) ($config['min_order_value'] ?? '0'));
        $stampsRequired = (int) ($config['stamps_required'] ?? 0);

        // One stamp per qualifying order (subtotal at/above the
        // minimum). min_order_value=0 → every order earns a stamp.
        $stampsEarned = $subtotalBaisas >= $minOrderBaisas ? 1 : 0;
        $newStamps = $stampCount + $stampsEarned;

        $eligible = [];
        if ($stampsRequired > 0 && $newStamps >= $stampsRequired) {
            $eligible[] = [
                'type' => 'stamp_reward',
                'stamps_per_reward' => $stampsRequired,
                'rewards_available' => intdiv($newStamps, $stampsRequired),
                'reward_type' => $config['reward_type'] ?? null,
                'reward_value' => $config['reward_value'] ?? null,
                'reward_product_id' => isset($config['reward_product_id']) ? (int) $config['reward_product_id'] : null,
            ];
        }

        return [
            'stampsEarned' => $stampsEarned,
            'pointsEarned' => 0,
            'eligibleRedemptions' => $eligible,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{stampsEarned: int, pointsEarned: int, eligibleRedemptions: list<array<string, mixed>>}
     */
    private static function evaluateSpend(array $config, int $subtotalBaisas, int $pointBalance): array
    {
        $pointsPerOmr = (int) ($config['points_per_omr'] ?? 0);
        $redemptionPoints = (int) ($config['redemption_points'] ?? 0);
        $minRedemption = (int) ($config['min_redemption_points'] ?? 0);
        $redemptionValue = (string) ($config['redemption_value'] ?? '0.000');

        // points = floor(omr_spent * points_per_omr), computed in
        // integer baisas space: baisas * rate / 1000.
        $pointsEarned = intdiv($subtotalBaisas * $pointsPerOmr, 1000);
        $newBalance = $pointBalance + $pointsEarned;

        $eligible = [];
        $threshold = max($minRedemption, $redemptionPoints);
        if ($redemptionPoints > 0 && $newBalance >= $threshold) {
            $eligible[] = [
                'type' => 'points',
                'points_per_redemption' => $redemptionPoints,
                'reward_value' => $redemptionValue,
                'max_redeemable_units' => intdiv($newBalance, $redemptionPoints),
            ];
        }

        return [
            'stampsEarned' => 0,
            'pointsEarned' => $pointsEarned,
            'eligibleRedemptions' => $eligible,
        ];
    }

    private static function toBaisas(string $omr): int
    {
        return (int) round(((float) $omr) * 1000);
    }
}
