<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — move finished-good units between two branches ("move 10 from A to
 * B"). Writes paired transfer_out (source) + transfer_in (destination) ledger
 * rows; refuses to overdraw the source branch. Atomic.
 */
final readonly class TransferProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
    ) {}

    public function handle(
        Product $product,
        Branch $from,
        Branch $to,
        string|float|int $quantity,
        ?string $note,
        User $actor,
    ): ProductStockMovement {
        if ((float) $quantity <= 0) {
            throw new RuntimeException('Transfer quantity must be greater than zero.');
        }
        if ((int) $from->id === (int) $to->id) {
            throw new RuntimeException('Choose two different branches.');
        }

        return DB::transaction(function () use ($product, $from, $to, $quantity, $note, $actor): ProductStockMovement {
            $fromBp = BranchProduct::query()
                ->where('branch_id', $from->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $have = ($fromBp === null || $fromBp->stock_qty === null) ? 0.0 : (float) $fromBp->stock_qty;

            if ((float) $quantity > $have + 1e-9) {
                throw new RuntimeException(sprintf(
                    'Not enough stock at the source branch: %.3f available.',
                    $have,
                ));
            }

            $this->writeMovement->handle(
                product: $product,
                branch: $from,
                type: ProductStockMovementType::TransferOut,
                quantity: -(float) $quantity,
                actor: $actor,
                note: $note,
            );

            return $this->writeMovement->handle(
                product: $product,
                branch: $to,
                type: ProductStockMovementType::TransferIn,
                quantity: $quantity,
                actor: $actor,
                note: $note,
            );
        });
    }
}
