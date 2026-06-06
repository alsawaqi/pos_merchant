<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\ProductStockMovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductStockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 7 — distribute a product's CENTRAL pool out to branches ("send 20 / 20 /
 * 10"). Debits the central balance and credits each branch's stock_qty, writing
 * paired allocation_out (central) + allocation_in (branch) ledger rows. Fails if
 * the requested total exceeds the central balance. Atomic across all branches.
 */
final readonly class AllocateProductStockAction
{
    public function __construct(
        private WriteProductStockMovementAction $writeMovement,
    ) {}

    /**
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $lines
     * @return list<ProductStockMovement>  the allocation_in legs (one per branch)
     */
    public function handle(
        Product $product,
        array $lines,
        ?string $note,
        User $actor,
    ): array {
        if ($lines === []) {
            throw new RuntimeException('Choose at least one branch to allocate to.');
        }

        $companyId = (int) $product->company_id;

        return DB::transaction(function () use ($product, $lines, $note, $actor, $companyId): array {
            // Lock the central balance row so two concurrent allocations can't
            // both pass the "enough stock" check and overdraw the pool.
            $central = ProductStock::query()
                ->where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();
            $available = $central === null ? 0.0 : (float) $central->quantity;

            $total = 0.0;
            foreach ($lines as $line) {
                $q = (float) $line['quantity'];
                if ($q <= 0) {
                    throw new RuntimeException('Each allocation quantity must be greater than zero.');
                }
                if ((int) $line['branch']->company_id !== $companyId) {
                    throw new RuntimeException('A selected branch does not belong to your company.');
                }
                $total += $q;
            }

            if ($total > $available + 1e-9) {
                throw new RuntimeException(sprintf(
                    'Not enough central stock: %.3f available, %.3f requested.',
                    $available,
                    $total,
                ));
            }

            $allocationIns = [];
            foreach ($lines as $line) {
                /** @var Branch $branch */
                $branch = $line['branch'];
                $q = $line['quantity'];

                $this->writeMovement->handle(
                    product: $product,
                    branch: null,
                    type: ProductStockMovementType::AllocationOut,
                    quantity: -(float) $q,
                    actor: $actor,
                    note: $note,
                );
                $allocationIns[] = $this->writeMovement->handle(
                    product: $product,
                    branch: $branch,
                    type: ProductStockMovementType::AllocationIn,
                    quantity: $q,
                    actor: $actor,
                    note: $note,
                );
            }

            return $allocationIns;
        });
    }
}
