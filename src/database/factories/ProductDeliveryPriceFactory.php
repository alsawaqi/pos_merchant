<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\DeliveryProvider;
use App\Models\Product;
use App\Models\ProductDeliveryPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductDeliveryPrice>
 *
 * Default: a 2.500 OMR override. Caller MUST pass BOTH
 *   ->for($product, 'product')
 *   ->for($provider, 'deliveryProvider')
 *   ->for($company, 'company')
 * where product.company_id == provider.company_id == company.id.
 * The (product, provider) unique constraint trips on collisions.
 */
class ProductDeliveryPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'delivery_provider_id' => DeliveryProvider::factory(),
            'company_id' => Company::factory(),
            'price' => '2.500',
        ];
    }
}
