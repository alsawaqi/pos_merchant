<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G4 — distribute an ingredient's CENTRAL warehouse pool out to branches
 * ("send 20 / 20 / 25"), the ingredient twin of {@see AllocateProductStockAction}.
 * Debits the central balance and credits each branch's pos_branch_stock,
 * writing paired allocation_out (central) + allocation_in (branch) ledger
 * rows. Fails if the requested total exceeds the central balance — the
 * warehouse never goes negative. Atomic across all branches.
 */
final readonly class AllocateIngredientStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
    ) {}

    /**
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $lines
     * @return list<StockMovement>  the allocation_in legs (one per branch)
     */
    public function handle(
        Ingredient $ingredient,
        array $lines,
        ?string $note,
        User $actor,
    ): array {
        if ($lines === []) {
            throw new RuntimeException('Choose at least one branch to allocate to.');
        }

        $companyId = (int) $ingredient->company_id;
        $unitCost = $ingredient->default_unit_cost ?? 0;

        return DB::transaction(function () use ($ingredient, $lines, $note, $actor, $companyId, $unitCost): array {
            // Lock the central balance row so two concurrent allocations
            // can't both pass the "enough stock" check and overdraw the pool.
            $central = IngredientStock::query()
                ->where('company_id', $companyId)
                ->where('ingredient_id', $ingredient->id)
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
                    branch: null,
                    ingredient: $ingredient,
                    type: StockMovementType::AllocationOut,
                    quantity: -(float) $q,
                    unitCostAtTime: $unitCost,
                    actor: $actor,
                    note: $note,
                );
                $allocationIns[] = $this->writeMovement->handle(
                    branch: $branch,
                    ingredient: $ingredient,
                    type: StockMovementType::AllocationIn,
                    quantity: $q,
                    unitCostAtTime: $unitCost,
                    actor: $actor,
                    note: $note,
                );
            }

            return $allocationIns;
        });
    }
}
