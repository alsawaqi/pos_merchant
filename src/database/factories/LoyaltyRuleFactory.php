<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LoyaltyRuleStatus;
use App\Enums\LoyaltyRuleType;
use App\Models\Company;
use App\Models\LoyaltyRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoyaltyRule>
 *
 * Default: an active spend_based (points) rule — 1 point per OMR,
 * 100 points = 5.000 OMR off. Caller passes ->for($company,
 * 'company'). Use visitBased() for the stamp-card variant.
 */
class LoyaltyRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => 'Points ' . strtoupper(Str::random(4)),
            'type' => LoyaltyRuleType::SpendBased->value,
            'config_json' => [
                'points_per_omr' => 1,
                'redemption_points' => 100,
                'redemption_value' => '5.000',
                'min_redemption_points' => 100,
            ],
            'validity_start' => null,
            'validity_end' => null,
            'status' => LoyaltyRuleStatus::Active->value,
        ];
    }

    public function visitBased(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Stamp Card ' . strtoupper(Str::random(4)),
            'type' => LoyaltyRuleType::VisitBased->value,
            'config_json' => [
                'min_order_value' => '2.000',
                'stamps_required' => 5,
                'reward_type' => 'free_product',
                'reward_value' => null,
                'reward_product_id' => null,
            ],
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => LoyaltyRuleStatus::Paused->value]);
    }
}
