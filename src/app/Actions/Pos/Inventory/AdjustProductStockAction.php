<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use RuntimeException;

/**
 * Phase 7 — manual correction to a product's central pool or a branch count
 * (physical recount). Signed delta + a required reason note. branch null =
 * adjust the central pool.
 */
final readonly class AdjustProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
    ) {}

    public function handle(
        Product $product,
        ?Branch $branch,
        string|float|int $signedQuantity,
        string $note,
        User $actor,
    ): ProductStockMovement {
        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Adjustment note is required — explain why the count changed.');
        }
        if ((float) $signedQuantity === 0.0) {
            throw new RuntimeException('Adjustment quantity cannot be zero.');
        }

        return $this->writeMovement->handle(
            product: $product,
            branch: $branch,
            type: ProductStockMovementType::Adjustment,
            quantity: $signedQuantity,
            actor: $actor,
            note: $note,
        );
    }
}
