<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientPurchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase A (Additions §2.4) — record an ingredient purchase BATCH.
 *
 * The piece-aware big brother of {@see RestockAction} (which stays
 * as the plain base-unit inflow primitive). Three entry shapes:
 *
 *   FIXED ratio   pieces only          milk: 5 bottles × 1 L/bottle.
 *                                      Requires the ingredient's piece
 *                                      config (or base unit = piece).
 *   LOOSE batch   pieces + units       tomatoes: 7 pieces weighing
 *                                      10 000 g. THIS batch's ratio
 *                                      (units ÷ pieces) becomes the
 *                                      ingredient's units_per_piece —
 *                                      LAST BATCH WINS; the old ratio
 *                                      survives on earlier purchase rows.
 *   PLAIN         units only           no piece bookkeeping; equivalent
 *                                      to a classic restock but still
 *                                      writes the batch row + cost update.
 *
 * Side effects (one transaction):
 *   1. pos_ingredient_purchases row (the batch record).
 *   2. Restock stock movement via WriteStockMovementAction
 *      (reference → the purchase row), which moves branch stock.
 *   3. Expense row for EXACTLY total_paid (RestockAction derives
 *      qty × cost which can drift by rounding; a purchase knows the
 *      real money), category 'ingredients'. Skipped when 0.
 *   4. Ingredient updates: units_per_piece on a loose batch,
 *      default_unit_cost = total_paid ÷ units (§2.4 step 4) whenever
 *      money was paid.
 *   5. Audit event inventory.purchase.recorded.
 */
final readonly class RecordPurchaseAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  string|float|int|null  $pieces     Physical pieces received (bottles, crates…)
     * @param  string|float|int|null  $units      Total PRIMARY units received. Required when
     *                                            $pieces is null; for a piece purchase it marks
     *                                            the batch LOOSE (weighed) and re-derives the ratio.
     * @param  string|float|int       $totalPaid  Money paid for the whole batch (OMR)
     */
    public function handle(
        Branch $branch,
        Ingredient $ingredient,
        string|float|int|null $pieces,
        string|float|int|null $units,
        string|float|int $totalPaid,
        ?Supplier $supplier,
        ?string $note,
        User $actor,
    ): IngredientPurchase {
        $companyId = $this->tenant->requiredId();
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $ingredient->company_id !== $companyId) {
            throw new RuntimeException('Ingredient does not belong to your company.');
        }
        if ($supplier !== null && (int) $supplier->company_id !== $companyId) {
            throw new RuntimeException('Supplier does not belong to your company.');
        }

        $pieces = $pieces !== null ? (float) $pieces : null;
        $units = $units !== null ? (float) $units : null;
        $totalPaid = (float) $totalPaid;

        if ($pieces === null && $units === null) {
            throw new RuntimeException('Enter the pieces received, the total quantity received, or both.');
        }
        if ($pieces !== null && $pieces <= 0) {
            throw new RuntimeException('Pieces received must be positive.');
        }
        if ($units !== null && $units <= 0) {
            throw new RuntimeException('Quantity received must be positive.');
        }
        if ($totalPaid < 0) {
            throw new RuntimeException('Total paid cannot be negative.');
        }
        if (
            $pieces !== null
            && ! $ingredient->allow_fractional_pieces
            && abs($pieces - round($pieces)) > 0.0000001
        ) {
            throw new RuntimeException('This ingredient is counted in whole pieces — fractional pieces are not allowed.');
        }

        $isLoose = false;
        $batchRatio = null;

        if ($pieces !== null && $units !== null) {
            // LOOSE batch — the weighed amount is authoritative,
            // and this batch's ratio becomes the new default.
            $isLoose = true;
            $batchRatio = $units / $pieces;
        } elseif ($pieces !== null) {
            // FIXED ratio — needs the ingredient's piece config.
            $batchRatio = $ingredient->unitsPerPiece();
            if ($batchRatio === null || $batchRatio <= 0) {
                throw new RuntimeException(
                    'This ingredient has no units-per-piece ratio. Set its piece unit first, or enter the total quantity received as well (loose batch).',
                );
            }
            $units = $pieces * $batchRatio;
        }

        // $units is now always set. Guard the (12,3) storage bound
        // the same way IngredientUnitConverter does.
        if ($units > 999999999.999) {
            throw new RuntimeException('The received quantity exceeds the maximum storable amount of 999,999,999.999 base units.');
        }

        $unitCost = $units > 0 ? $totalPaid / $units : 0.0;

        return DB::transaction(function () use (
            $branch,
            $ingredient,
            $pieces,
            $units,
            $totalPaid,
            $unitCost,
            $batchRatio,
            $isLoose,
            $supplier,
            $note,
            $actor,
            $companyId,
        ): IngredientPurchase {
            // Step 1: the batch row (movement linked in step 2 —
            // the movement references the purchase, so the purchase
            // must exist first).
            /** @var IngredientPurchase $purchase */
            $purchase = IngredientPurchase::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'ingredient_id' => $ingredient->id,
                'supplier_id' => $supplier?->id,
                'pieces_received' => $pieces !== null ? number_format($pieces, 3, '.', '') : null,
                'units_received' => number_format($units, 3, '.', ''),
                'total_paid' => number_format($totalPaid, 3, '.', ''),
                'unit_cost' => number_format($unitCost, 6, '.', ''),
                'units_per_piece_at_purchase' => $batchRatio !== null ? number_format($batchRatio, 4, '.', '') : null,
                'is_loose' => $isLoose,
                'note' => $note,
                'recorded_by_user_id' => $actor->getKey(),
                'occurred_at' => now(),
            ]);

            // Step 2: the inflow movement (moves branch stock,
            // writes its own audit row).
            $movement = $this->writeMovement->handle(
                branch: $branch,
                ingredient: $ingredient,
                type: StockMovementType::Restock,
                quantity: number_format($units, 3, '.', ''),
                unitCostAtTime: number_format($unitCost, 3, '.', ''),
                referenceType: IngredientPurchase::class,
                referenceId: $purchase->id,
                actor: $actor,
                note: $note,
            );
            $purchase->forceFill(['stock_movement_id' => $movement->id])->save();

            // Step 3: the cash-out, for EXACTLY what was paid.
            if ($totalPaid > 0) {
                $unitLabel = $ingredient->unit?->value ?? '';
                $desc = $pieces !== null
                    ? trim(sprintf(
                        'Ingredient purchase: %s %s (%s %s) of %s',
                        rtrim(rtrim(number_format($pieces, 3, '.', ''), '0'), '.'),
                        $ingredient->piece_unit_label ?? 'piece(s)',
                        rtrim(rtrim(number_format($units, 3, '.', ''), '0'), '.'),
                        $unitLabel,
                        $ingredient->name,
                    ))
                    : trim(sprintf(
                        'Ingredient purchase: %s %s of %s',
                        rtrim(rtrim(number_format($units, 3, '.', ''), '0'), '.'),
                        $unitLabel,
                        $ingredient->name,
                    ));
                Expense::query()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branch->id,
                    'category' => ExpenseCategory::Ingredients->value,
                    'amount' => number_format($totalPaid, 3, '.', ''),
                    'note' => ($note !== null && $note !== '') ? $desc.' - '.$note : $desc,
                    'logged_by_portal_user_id' => $actor->getKey(),
                    'logged_at' => $purchase->occurred_at,
                    'status' => ExpenseStatus::Recorded->value,
                ]);
            }

            // Step 4: ingredient updates — last batch wins.
            $dirty = [];
            if ($isLoose && $batchRatio !== null && $ingredient->piece_unit_label !== null) {
                $dirty['units_per_piece'] = number_format($batchRatio, 4, '.', '');
            }
            if ($totalPaid > 0) {
                $dirty['default_unit_cost'] = number_format($unitCost, 3, '.', '');
            }
            if ($dirty !== []) {
                $ingredient->forceFill($dirty)->save();
            }

            // Step 5: purchase-specific audit row (the movement's
            // audit row lacks the pieces/money detail).
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.purchase.recorded',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: IngredientPurchase::class,
                auditableId: $purchase->id,
                newValues: [
                    'ingredient_id' => $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'pieces_received' => $pieces !== null ? number_format($pieces, 3, '.', '') : null,
                    'units_received' => number_format($units, 3, '.', ''),
                    'total_paid' => number_format($totalPaid, 3, '.', ''),
                    'unit_cost' => number_format($unitCost, 6, '.', ''),
                    'is_loose' => $isLoose,
                    'supplier_id' => $supplier?->id,
                ],
            ));

            return $purchase->fresh(['ingredient', 'supplier']);
        });
    }
}
