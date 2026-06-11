<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\Company;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Offer>
 *
 * Default: an active spend_get offer ("spend ≥ 5 OMR → 10% off"),
 * auto-applying, no validity window restrictions — the only type whose
 * canonical config needs no tenant-owned product/category ids, so the
 * factory is safe without extra seeding. Caller passes
 * ->for($company, 'company') for tenant consistency.
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'name' => 'Test Offer ' . strtoupper(Str::random(4)),
            'name_ar' => null,
            'type' => OfferType::SpendGet->value,
            'config' => [
                'min_subtotal_baisas' => 5000,
                'reward_type' => 'percent_off',
                'reward_value' => 10.0,
                'reward_product_id' => null,
            ],
            'auto_apply' => true,
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'max_per_order' => null,
            'status' => OfferStatus::Active->value,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => OfferStatus::Paused->value]);
    }

    /**
     * A cashier-picked fixed-price bundle. Callers must supply
     * tenant-owned product ids for the groups.
     *
     * @param  list<int>  $productIds
     */
    public function bundle(array $productIds, int $priceBaisas = 2500): static
    {
        return $this->state(fn (): array => [
            'type' => OfferType::Bundle->value,
            'auto_apply' => false,
            'config' => [
                'price_baisas' => $priceBaisas,
                'groups' => [
                    ['label' => 'Main', 'label_ar' => null, 'product_ids' => $productIds, 'qty' => 1],
                ],
            ],
        ]);
    }
}
