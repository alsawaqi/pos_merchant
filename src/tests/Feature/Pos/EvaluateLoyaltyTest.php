<?php

declare(strict_types=1);

/**
 * Loyalty refactor — pure-function evaluator coverage.
 *
 * EvaluateLoyalty is pure (no DB), so these build unsaved
 * LoyaltyRule models and assert the earn + eligible-redemption
 * math directly. Mirrors the shape the Flutter device will port.
 */

use App\Actions\Pos\Loyalty\EvaluateLoyalty;
use App\Models\LoyaltyRule;

function spendRule(array $cfg = []): LoyaltyRule
{
    return new LoyaltyRule([
        'type' => 'spend_based',
        'config_json' => array_merge([
            'points_per_omr' => 1,
            'redemption_points' => 100,
            'redemption_value' => '5.000',
            'min_redemption_points' => 100,
        ], $cfg),
    ]);
}

function visitRule(array $cfg = []): LoyaltyRule
{
    return new LoyaltyRule([
        'type' => 'visit_based',
        'config_json' => array_merge([
            'min_order_value' => '2.000',
            'stamps_required' => 5,
            'reward_type' => 'free_product',
            'reward_value' => null,
            'reward_product_id' => null,
        ], $cfg),
    ]);
}

// =================== SPEND-BASED ===================

it('earns floor(points) from spend', function (): void {
    // 12.500 OMR * 1 point/OMR = 12.5 → floor 12.
    $r = EvaluateLoyalty::run(['subtotal' => '12.500'], spendRule());
    expect($r['pointsEarned'])->toBe(12);
    expect($r['stampsEarned'])->toBe(0);
});

it('scales points by the earn ratio', function (): void {
    $r = EvaluateLoyalty::run(['subtotal' => '10.000'], spendRule(['points_per_omr' => 2]));
    expect($r['pointsEarned'])->toBe(20);
});

it('unlocks a redemption once the post-earn balance crosses the threshold', function (): void {
    // balance 90 + earned 12 = 102 ≥ 100.
    $r = EvaluateLoyalty::run(['subtotal' => '12.500'], spendRule(), ['point_balance' => 90]);
    expect($r['eligibleRedemptions'])->toHaveCount(1);
    expect($r['eligibleRedemptions'][0]['type'])->toBe('points');
    expect($r['eligibleRedemptions'][0]['points_per_redemption'])->toBe(100);
    expect($r['eligibleRedemptions'][0]['reward_value'])->toBe('5.000');
    expect($r['eligibleRedemptions'][0]['max_redeemable_units'])->toBe(1);
});

it('offers no redemption below the threshold', function (): void {
    $r = EvaluateLoyalty::run(['subtotal' => '12.500'], spendRule(), ['point_balance' => 0]);
    expect($r['eligibleRedemptions'])->toBe([]);
});

// =================== VISIT-BASED ===================

it('earns a stamp at or above the minimum order value', function (): void {
    $r = EvaluateLoyalty::run(['subtotal' => '2.000'], visitRule());
    expect($r['stampsEarned'])->toBe(1);
    expect($r['pointsEarned'])->toBe(0);
});

it('earns no stamp below the minimum order value', function (): void {
    $r = EvaluateLoyalty::run(['subtotal' => '1.999'], visitRule());
    expect($r['stampsEarned'])->toBe(0);
});

it('unlocks a stamp reward when the required count is reached', function (): void {
    // 4 + 1 = 5 ≥ 5.
    $r = EvaluateLoyalty::run(['subtotal' => '3.000'], visitRule(), ['stamp_count' => 4]);
    expect($r['eligibleRedemptions'])->toHaveCount(1);
    expect($r['eligibleRedemptions'][0]['type'])->toBe('stamp_reward');
    expect($r['eligibleRedemptions'][0]['rewards_available'])->toBe(1);
});

it('reports multiple available rewards', function (): void {
    // 10 + 1 = 11, required 5 → 2 rewards.
    $r = EvaluateLoyalty::run(['subtotal' => '3.000'], visitRule(), ['stamp_count' => 10]);
    expect($r['eligibleRedemptions'][0]['rewards_available'])->toBe(2);
});
