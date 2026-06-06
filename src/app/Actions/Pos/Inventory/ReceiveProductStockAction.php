<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use RuntimeException;

/**
 * Phase 7 — receive finished goods into a product's CENTRAL pool ("I have 50").
 * Positive inflow credited to pos_product_stock.quantity, before the merchant
 * allocates units out to branches.
 */
final readonly class ReceiveProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
    ) {}

    public function handle(
        Product $product,
        string|float|int $quantity,
        ?string $note,
        User $actor,
    ): ProductStockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        return $this->writeMovement->handle(
            product: $product,
            branch: null,
            type: ProductStockMovementType::Received,
            quantity: $quantity,
            actor: $actor,
            note: $note,
        );
    }
}
