<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — soft-delete an ingredient.
 *
 * Refuses if ANY branch still holds non-zero stock of this
 * ingredient — the merchant must first adjust the stock to
 * zero (waste it / transfer it / sell it). Otherwise the
 * deletion would orphan a real physical asset on the books.
 *
 * Phase 5b will add a second guard: refuse when the ingredient
 * is referenced by any product recipe. That check lives in
 * the Phase 5b action because Recipes doesn't exist yet.
 *
 * Soft delete keeps historical stock_movements + recipe
 * references resolvable via withTrashed().
 *
 * Audit event: inventory.ingredient.deleted with snapshot.
 */
final readonly class DeleteIngredientAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Ingredient $ingredient, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $ingredient->company_id !== $companyId) {
            abort(404);
        }

        $branchesWithStock = BranchStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('quantity', '!=', '0.000')
            ->count();
        if ($branchesWithStock > 0) {
            throw new RuntimeException(sprintf(
                'Cannot delete ingredient — %d branch(es) still hold stock. Adjust the stock to zero first.',
                $branchesWithStock,
            ));
        }

        DB::transaction(function () use ($ingredient, $actor, $companyId): void {
            $snapshot = [
                'name' => $ingredient->name,
                'unit' => $ingredient->unit?->value,
                'default_unit_cost' => (string) $ingredient->default_unit_cost,
            ];
            $ingredientId = $ingredient->id;

            $ingredient->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: $ingredientId,
                oldValues: $snapshot,
            ));
        });
    }
}
