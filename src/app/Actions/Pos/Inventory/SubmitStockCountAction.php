<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WasteRecord;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase A (Additions §2.8) — submit a day-end physical stock count
 * for one branch and reconcile it against the running balance.
 *
 * Per ingredient line:
 *   counted    staff enter PIECES ("5 bottles on the shelf") which
 *              convert via units_per_piece, or primary units
 *              directly for non-piece ingredients.
 *   expected   the CURRENT running balance — by construction equal
 *              to opening + purchases − consumption ± transfers,
 *              because every one of those already flowed through
 *              the movement ledger.
 *   variance   counted − expected.
 *     < 0  →  WasteRecord with reason reconciliation_variance + the
 *             signed-negative waste movement (via RecordWasteAction)
 *             so the Loss/Waste report picks it up with zero extra
 *             wiring — exactly what the Additions doc prescribes.
 *     > 0  →  positive Adjustment movement (found MORE than booked;
 *             calling that "waste" would corrupt the waste report).
 *     = 0  →  no movement; the line still records the clean count.
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
                'counted_units' => round((float) $countedUnits, 3),
            ];
        }

        $note = ($note !== null && trim($note) !== '') ? trim($note) : null;

        return DB::transaction(function () use ($branch, $resolved, $note, $actor, $companyId): StockCount {
            /** @var StockCount $count */
            $count = StockCount::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'note' => $note,
                'recorded_by_user_id' => $actor->getKey(),
                'counted_at' => now(),
            ]);

            $shortfallValue = 0.0;
            $linesWithVariance = 0;

            foreach ($resolved as $line) {
                /** @var Ingredient $ingredient */
                $ingredient = $line['ingredient'];

                $expected = (float) (BranchStock::query()
                    ->where('branch_id', $branch->id)
                    ->where('ingredient_id', $ingredient->id)
                    ->value('quantity') ?? 0.0);
                $variance = round($line['counted_units'] - $expected, 3);

                $movementId = null;
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
                    );
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
                    );
                    $movementId = $movement->id;
                    $linesWithVariance++;
                }

                StockCountLine::query()->create([
                    'stock_count_id' => $count->id,
                    'ingredient_id' => $ingredient->id,
                    'counted_pieces' => $line['counted_pieces'] !== null
                        ? number_format($line['counted_pieces'], 3, '.', '')
                        : null,
                    'counted_units' => number_format($line['counted_units'], 3, '.', ''),
                    'expected_units' => number_format($expected, 3, '.', ''),
                    'variance_units' => number_format($variance, 3, '.', ''),
                    'unit_cost_at_time' => (string) $ingredient->default_unit_cost,
                    'stock_movement_id' => $movementId,
                ]);
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
     * @param  array{ingredient: Ingredient, counted_pieces: float|null, counted_units: float}  $line
     */
    private function lineNote(array $line, float $expected, ?string $note): string
    {
        $ingredient = $line['ingredient'];
        $counted = $line['counted_pieces'] !== null
            ? sprintf(
                '%s %s (= %s %s)',
                rtrim(rtrim(number_format($line['counted_pieces'], 3, '.', ''), '0'), '.'),
                $ingredient->piece_unit_label ?? 'piece(s)',
                number_format($line['counted_units'], 3, '.', ''),
                $ingredient->unit?->value ?? '',
            )
            : sprintf('%s %s', number_format($line['counted_units'], 3, '.', ''), $ingredient->unit?->value ?? '');

        $text = sprintf('Day-end stock count: counted %s, expected %s.', $counted, number_format($expected, 3, '.', ''));

        return $note !== null ? $text.' '.$note : $text;
    }
}
