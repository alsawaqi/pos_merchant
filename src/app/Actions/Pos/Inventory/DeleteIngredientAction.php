<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\ProductRecipe;
use App\Models\RestockRequestLine;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5a — soft-delete an ingredient.
 *
 * Three guards before any write:
 *
 *   1. Refuses if ANY branch still holds non-zero stock of
 *      this ingredient — the merchant must first adjust the
 *      stock to zero (waste it / transfer it / sell it).
 *      Otherwise the deletion would orphan a real physical
 *      asset on the books.
 *
 *   2. Phase 5b: refuses if any product recipe references
 *      this ingredient. The merchant must edit those recipes
 *      first. Soft-deleting mid-recipe would break the Phase
 *      8 sale-consumption pipeline (the snapshot still writes
 *      but the live deduction would silently fail).
 *
 *   3. Phase 5c: refuses if any non-terminal restock request
 *      (draft / submitted / approved) has a line for this
 *      ingredient. The merchant must cancel or fulfil those
 *      requests first. Terminal-state requests
 *      (fulfilled / rejected / cancelled) are historical and
 *      OK to retain a stale reference (the line snapshot
 *      preserves ingredient_id but the UI rendering tolerates
 *      a missing ingredient relation).
 *
 * Soft delete keeps historical stock_movements + recipe
 * version snapshots resolvable via withTrashed().
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

        // P-G4 — the central warehouse is a second place stock lives; a
        // non-zero pool blocks deletion for the same orphaned-asset reason
        // as guard #1.
        $centralHoldsStock = IngredientStock::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('quantity', '!=', '0.000')
            ->exists();
        if ($centralHoldsStock) {
            throw new RuntimeException(
                'Cannot delete ingredient — the central warehouse still holds stock. Adjust or allocate it to zero first.',
            );
        }

        // Phase 5b — recipe-reference guard. Active product
        // recipes that name this ingredient block deletion.
        $recipeCount = ProductRecipe::query()
            ->where('ingredient_id', $ingredient->id)
            ->count();
        if ($recipeCount > 0) {
            throw new RuntimeException(sprintf(
                'Cannot delete ingredient — %d product recipe(s) still reference it. Edit those recipes first.',
                $recipeCount,
            ));
        }

        // Phase 5c — active-restock-request guard. Lines on
        // requests still in flight (draft / submitted / approved)
        // would lose their meaning if the ingredient vanished;
        // Fulfilled / Rejected / Cancelled requests are
        // historical and are fine to keep referencing a deleted
        // ingredient (the snapshot lines preserve the data).
        $openRequestLineCount = RestockRequestLine::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereHas('request', static function ($q): void {
                $q->whereIn('status', [
                    RestockRequestStatus::Draft->value,
                    RestockRequestStatus::Submitted->value,
                    RestockRequestStatus::Approved->value,
                ]);
            })
            ->count();
        if ($openRequestLineCount > 0) {
            throw new RuntimeException(sprintf(
                'Cannot delete ingredient — %d open restock request(s) still reference it. Cancel or fulfil those first.',
                $openRequestLineCount,
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
