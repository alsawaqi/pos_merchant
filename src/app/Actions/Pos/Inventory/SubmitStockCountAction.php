<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockCountLineContainer;
use App\Support\Inventory\ContainerAmount;
use App\Support\Inventory\Containers;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WasteRecord;
use App\Support\MerchantTenantContext;
use App\Support\StockDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase A (Additions §2.8) — submit a day-end physical stock count
 * for one branch and reconcile it against the book balance.
 *
 * Per ingredient line:
 *   counted    staff enter PIECES ("5 bottles on the shelf") which
 *              convert via units_per_piece, or primary units
 *              directly for non-piece ingredients.
 *   expected   LAUNCH-P2 P2-6 — the book balance AT THE COUNT MOMENT:
 *              every movement dated before counted_at (sale time for
 *              device sales, not sync time), i.e. the current balance
 *              minus whatever is dated at or after the count. A sale
 *              made before the count that syncs later is folded in when
 *              it arrives ({@see FoldLateMovementIntoCountAction}).
 *   variance   counted − expected.
 *     < 0  →  WasteRecord with reason reconciliation_variance + the
 *             signed-negative waste movement (via RecordWasteAction)
 *             so the Loss/Waste report picks it up with zero extra
 *             wiring — exactly what the Additions doc prescribes.
 *     > 0  →  positive Adjustment movement (found MORE than booked;
 *             calling that "waste" would corrupt the waste report).
 *     = 0  →  no movement; the line still records the clean count.
 *   Variance movements are dated at the count moment, so the balance
 *   after the count is what was counted plus later movements.
 *
 * Everything happens in ONE transaction: a count either fully
 * reconciles or doesn't exist. The header + lines are the queryable
 * record behind the Inventory Consumption report's counted/variance
 * columns and the dashboard variance tile.
 */
final readonly class SubmitStockCountAction
{
    public function __construct(
        private RecordWasteAction $recordWaste,
        private AdjustStockAction $adjustStock,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private ContainerBreakdownAction $breakdown,
    ) {}

    /**
     * @param  list<array{ingredient: Ingredient, counted_pieces?: string|float|int|null, counted_units?: string|float|int|null}>  $lines
     */
    public function handle(
        Branch $branch,
        array $lines,
        ?string $note,
        User $actor,
    ): StockCount {
        $companyId = $this->tenant->requiredId();
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ($lines === []) {
            throw new RuntimeException('A stock count needs at least one ingredient line.');
        }

        // Resolve every line to counted base units BEFORE the
        // transaction so validation errors can't leave a half count.
        $resolved = [];
        $seen = [];
        foreach ($lines as $line) {
            $ingredient = $line['ingredient'];
            if ((int) $ingredient->company_id !== $companyId) {
                throw new RuntimeException('Ingredient does not belong to your company.');
            }
            if (isset($seen[$ingredient->id])) {
                throw new RuntimeException(sprintf('Ingredient "%s" appears twice in the count.', $ingredient->name));
            }
            $seen[$ingredient->id] = true;

            $countedPieces = isset($line['counted_pieces']) && $line['counted_pieces'] !== null
                ? (float) $line['counted_pieces']
                : null;
            $countedUnits = isset($line['counted_units']) && $line['counted_units'] !== null
                ? (float) $line['counted_units']
                : null;

            if ($countedPieces === null && $countedUnits === null) {
                throw new RuntimeException(sprintf('Enter a counted amount for "%s".', $ingredient->name));
            }
            if ($countedPieces !== null && $countedPieces < 0) {
                throw new RuntimeException('Counted pieces cannot be negative.');
            }
            if ($countedUnits !== null && $countedUnits < 0) {
                throw new RuntimeException('Counted quantity cannot be negative.');
            }

            if ($countedPieces !== null) {
                if (! $ingredient->allow_fractional_pieces && abs($countedPieces - round($countedPieces)) > 0.0000001) {
                    throw new RuntimeException(sprintf(
                        '"%s" is counted in whole pieces — fractional pieces are not allowed.',
                        $ingredient->name,
                    ));
                }
                $ratio = $ingredient->unitsPerPiece();
                if ($ratio === null || $ratio <= 0) {
                    throw new RuntimeException(sprintf(
                        '"%s" has no units-per-piece ratio — count it in its base unit instead.',
                        $ingredient->name,
                    ));
                }
                // Pieces are authoritative when both were sent.
                $countedUnits = $countedPieces * $ratio;
            }

            $resolved[] = [
                'ingredient' => $ingredient,
                'counted_pieces' => $countedPieces,
                'counted_units' => round((float) $countedUnits, StockDecimal::QUANTITY_SCALE),
                // LAUNCH review add-on (D2) — the containers counted, if any.
                'containers' => $line['containers'] ?? [],
            ];
        }

        $note = ($note !== null && trim($note) !== '') ? trim($note) : null;

        return DB::transaction(function () use ($branch, $resolved, $note, $actor, $companyId): StockCount {
            // The count moment: whole seconds, the precision the ledger
            // stores occurred_at in.
            $countedAt = now()->startOfSecond();

            /** @var StockCount $count */
            $count = StockCount::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'note' => $note,
                'recorded_by_user_id' => $actor->getKey(),
                'counted_at' => $countedAt,
            ]);

            $shortfallValue = 0.0;
            $linesWithVariance = 0;

            foreach ($resolved as $line) {
                /** @var Ingredient $ingredient */
                $ingredient = $line['ingredient'];

                $expected = $this->bookBalanceAt($branch, $ingredient, $countedAt);
                $variance = round($line['counted_units'] - $expected, StockDecimal::QUANTITY_SCALE);

                $movementId = null;
                $wasteId = null;
                if ($variance < 0) {
                    // Shortfall — the doc's "waste / loss movement
                    // with reason = reconciliation variance".
                    $waste = $this->recordWaste->handle(
                        branch: $branch,
                        ingredient: $ingredient,
                        quantity: abs($variance),
                        reason: WasteReason::ReconciliationVariance,
                        actor: $actor,
                        notes: $this->lineNote($line, $expected, $note),
                        occurredAt: $countedAt,
                    );
                    $wasteId = (int) $waste->id;
                    $movementId = StockMovement::query()
                        ->where('reference_type', WasteRecord::class)
                        ->where('reference_id', $waste->id)
                        ->value('id');
                    $shortfallValue += abs($variance) * (float) $ingredient->default_unit_cost;
                    $linesWithVariance++;
                } elseif ($variance > 0) {
                    // Overage — found more than booked.
                    $movement = $this->adjustStock->handle(
                        branch: $branch,
                        ingredient: $ingredient,
                        signedQuantity: $variance,
                        note: $this->lineNote($line, $expected, $note),
                        actor: $actor,
                        occurredAt: $countedAt,
                    );
                    $movementId = $movement->id;
                    $linesWithVariance++;
                }

                /** @var StockCountLine $countLine */
                $countLine = StockCountLine::query()->create([
                    'stock_count_id' => $count->id,
                    'ingredient_id' => $ingredient->id,
                    'counted_pieces' => $line['counted_pieces'] !== null
                        ? StockDecimal::quantity($line['counted_pieces'])
                        : null,
                    'counted_units' => StockDecimal::quantity($line['counted_units']),
                    'expected_units' => StockDecimal::quantity($expected),
                    'variance_units' => StockDecimal::quantity($variance),
                    'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                    'stock_movement_id' => $movementId,
                    'waste_record_id' => $wasteId,
                ]);

                $this->countBreakdown($branch, $ingredient, $line, $countLine, $actor, $countedAt, $companyId);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.stock_count.submitted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: StockCount::class,
                auditableId: $count->id,
                newValues: [
                    'lines' => count($resolved),
                    'lines_with_variance' => $linesWithVariance,
                    'shortfall_value' => number_format($shortfallValue, 3, '.', ''),
                    'note' => $note,
                ],
            ));

            return $count->fresh(['lines.ingredient', 'branch']);
        });
    }

    /**
     * LAUNCH review add-on (B3, D2; tester call 9) — what a count does to the
     * breakdown by container:
     *   - counted BY CONTAINER: the branch breakdown is SET to exactly the
     *     containers counted (stamped containers_counted_at), and each
     *     container row is kept on the line;
     *   - otherwise the legacy rule (old apps and a total-only count): pieces
     *     counted in the count container when it is the item's ONLY leaf
     *     container → set the breakdown to that; a total of 0 → clear it;
     *     anything else → leave it and stamp containers_total_count_at.
     * The count shortfall waste never takes from the breakdown (the count set
     * it already).
     *
     * @param  array{ingredient: Ingredient, counted_pieces: float|null, counted_units: float, containers: list<array{container: IngredientAltUnit, pieces: \Brick\Math\BigDecimal}>}  $line
     */
    private function countBreakdown(Branch $branch, Ingredient $ingredient, array $line, StockCountLine $countLine, User $actor, Carbon $countedAt, int $companyId): void
    {
        $ref = [
            'reference_type' => StockCountLine::class,
            'reference_id' => (int) $countLine->id,
            'stock_movement_id' => $countLine->stock_movement_id !== null ? (int) $countLine->stock_movement_id : null,
            'occurred_at' => $countedAt,
        ];

        if ($line['containers'] !== []) {
            foreach ($line['containers'] as $row) {
                StockCountLineContainer::query()->create([
                    'stock_count_line_id' => $countLine->id,
                    'company_id' => $companyId,
                    'container_id' => $row['container']->id,
                    'container_label' => ContainerAmount::label($ingredient, $row['container']),
                    'container_factor' => (string) $row['container']->factor,
                    'pieces' => (string) $row['pieces'],
                ]);
            }
            $this->breakdown->set($ingredient, (int) $branch->id, ContainerBreakdownAction::leaves($ingredient, $line['containers']), 'count', $actor, $ref);

            return;
        }

        $leaves = Containers::of($ingredient)->filter(static fn (IngredientAltUnit $c): bool => $c->contains_unit_id === null);
        $countContainer = $ingredient->count_container_id !== null
            ? $leaves->first(static fn (IngredientAltUnit $c): bool => (int) $c->id === (int) $ingredient->count_container_id)
            : null;
        if ($line['counted_pieces'] !== null && $countContainer !== null && $leaves->count() === 1) {
            $this->breakdown->set($ingredient, (int) $branch->id, [(int) $countContainer->id => Containers::decimal((string) $line['counted_pieces'])], 'count', $actor, $ref);
        } elseif ((float) $line['counted_units'] === 0.0) {
            $this->breakdown->set($ingredient, (int) $branch->id, [], 'count', $actor, $ref);
        } else {
            $this->breakdown->stampTotalOnly($ingredient, (int) $branch->id, $countedAt);
        }
    }

    /**
     * The branch's book balance of an ingredient AT $at: the running balance
     * minus every movement dated after $at (movements that happened after
     * the count moment, e.g. sales already synced from a till whose clock is
     * ahead). Uses the movement time, never the sync time. A movement in the
     * count's own second that is already on the books counts as before it.
     */
    private function bookBalanceAt(Branch $branch, Ingredient $ingredient, Carbon $at): float
    {
        $balance = (float) (BranchStock::query()
            ->where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('quantity') ?? 0.0);
        $after = (float) DB::table('pos_stock_movements')
            ->where('branch_id', $branch->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('occurred_at', '>', $at)
            ->sum('quantity');

        return round($balance - $after, StockDecimal::QUANTITY_SCALE);
    }

    /**
     * @param  array{ingredient: Ingredient, counted_pieces: float|null, counted_units: float}  $line
     */
    private function lineNote(array $line, float $expected, ?string $note): string
    {
        $ingredient = $line['ingredient'];
        $counted = $line['counted_pieces'] !== null
            ? sprintf(
                '%s %s (= %s %s)',
                StockDecimal::format($line['counted_pieces'], 0, StockDecimal::QUANTITY_SCALE),
                $ingredient->piece_unit_label ?? 'piece(s)',
                StockDecimal::quantity($line['counted_units']),
                $ingredient->unit?->value ?? '',
            )
            : sprintf('%s %s', StockDecimal::quantity($line['counted_units']), $ingredient->unit?->value ?? '');

        $text = sprintf('Day-end stock count: counted %s, expected %s.', $counted, StockDecimal::quantity($expected));

        return $note !== null ? $text.' '.$note : $text;
    }
}
