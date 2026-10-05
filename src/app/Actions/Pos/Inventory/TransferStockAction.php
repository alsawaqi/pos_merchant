<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferLine;
use App\Models\BranchTransferLineContainer;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\Inventory\ContainerAmount;
use App\Support\Inventory\Containers;
use App\Support\MerchantTenantContext;
use App\Support\StockDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move stock between two branches (§5.6).
 *
 * Immediate + atomic: in one transaction we write the transfer header + lines,
 * and for EACH line a paired transfer_out movement at the source branch and a
 * transfer_in at the destination — both through {@see WriteStockMovementAction}
 * so the ledger invariant (SUM(movements) == branch_stock) holds per branch.
 * Either the whole transfer lands or none of it does.
 *
 * Guards:
 *   - from/to branches both belong to the actor's company, and differ.
 *   - every ingredient belongs to the company; each appears at most once.
 *   - quantities are positive; source must hold enough (no negative stock —
 *     unlike sale consumption, a transfer is a deliberate manual act, so we
 *     refuse to over-draw rather than silently going negative).
 *
 * Unit cost moves with the stock: each line snapshots the source ingredient's
 * default_unit_cost, and both movements carry it so COGS stays consistent.
 */
final readonly class TransferStockAction
{
    public function __construct(
        private WriteStockMovementAction $writeMovement,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IngredientUnitConverter $units,
        private ContainerBreakdownAction $breakdown,
    ) {}

    /**
     * @param  list<array{ingredient_uuid: string, quantity?: string|float|int|null, unit?: string|null, containers?: list<array{container_uuid: string, pieces: string|float|int}>}>  $lines
     */
    public function handle(Branch $from, Branch $to, array $lines, User $actor, ?string $note = null): BranchTransfer
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $from->company_id !== $companyId || (int) $to->company_id !== $companyId) {
            throw new RuntimeException('Both branches must belong to your company.');
        }
        if ((int) $from->id === (int) $to->id) {
            throw new RuntimeException('Source and destination branches must be different.');
        }
        if ($lines === []) {
            throw new RuntimeException('A transfer needs at least one line.');
        }

        // Resolve + validate every line up front so we fail before writing any
        // movement (the DB transaction would roll back regardless, but this
        // gives a clean message naming the offending ingredient).
        $resolved = [];
        $seen = [];
        foreach ($lines as $line) {
            $ingredient = Ingredient::query()
                ->where('company_id', $companyId)
                ->where('uuid', $line['ingredient_uuid'])
                ->first();
            if ($ingredient === null) {
                throw new RuntimeException('Ingredient does not belong to your company.');
            }
            // LAUNCH-P3 P3-4 — a prep item has no stock to move.
            $ingredient->ensureStocked();
            if (isset($seen[$ingredient->id])) {
                throw new RuntimeException('Ingredient "'.$ingredient->name.'" is listed more than once.');
            }
            $seen[$ingredient->id] = true;

            // LAUNCH review add-on (D1) — by container: [{container_uuid, pieces}]
            // and an amount that fills in as pieces × size and may only be
            // LOWERED (3 bottles = 2.5 l when one is half used). An amount
            // typed in a container ("2 crates") is by container too.
            $rows = ContainerAmount::rows($ingredient, (array) ($line['containers'] ?? []));
            $unit = isset($line['unit']) && is_string($line['unit']) && $line['unit'] !== '' ? $line['unit'] : null;
            if ($rows !== []) {
                $quantity = (float) (string) ContainerAmount::amount($ingredient, $rows, $line['quantity'] ?? null, $unit, $this->units);
            } else {
                if (! isset($line['quantity']) || $line['quantity'] === null || $line['quantity'] === '') {
                    throw new RuntimeException('Enter how much "'.$ingredient->name.'" to transfer, or its containers.');
                }
                // #13 — convert the entered quantity to base units before the
                // positivity + available-stock checks (so both compare base-to-base).
                $quantity = round($this->units->toBase($ingredient, $line['quantity'], $unit), StockDecimal::QUANTITY_SCALE);
                $asContainer = Containers::resolve($ingredient, $unit);
                if ($asContainer !== null) {
                    $rows = [['container' => $asContainer, 'pieces' => Containers::decimal($line['quantity'])]];
                }
            }
            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity for "'.$ingredient->name.'" must be positive.');
            }

            $available = (float) ($ingredient->branchStock()->where('branch_id', $from->id)->value('quantity') ?? 0);
            if ($quantity > $available) {
                throw new RuntimeException(sprintf(
                    'Not enough "%s" at the source branch: have %s, transferring %s.',
                    $ingredient->name,
                    StockDecimal::format($available, 0, 4),
                    StockDecimal::format($quantity, 0, 4),
                ));
            }

            $resolved[] = ['ingredient' => $ingredient, 'quantity' => $quantity, 'containers' => $rows];
        }

        return DB::transaction(function () use ($from, $to, $resolved, $actor, $note, $companyId): BranchTransfer {
            /** @var BranchTransfer $transfer */
            $transfer = BranchTransfer::query()->create([
                'company_id' => $companyId,
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'transferred_by_user_id' => $actor->getKey(),
                'transferred_at' => now(),
                'note' => $note,
            ]);

            foreach ($resolved as $row) {
                /** @var Ingredient $ingredient */
                $ingredient = $row['ingredient'];
                $quantity = $row['quantity'];
                $unitCost = $ingredient->default_unit_cost ?? 0;

                /** @var BranchTransferLine $transferLine */
                $transferLine = BranchTransferLine::query()->create([
                    'branch_transfer_id' => $transfer->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => (string) $quantity,
                    'unit_at_set' => $ingredient->unit->value,
                    'unit_cost_at_time' => (string) $unitCost,
                ]);

                // LAUNCH review add-on (D1) — the containers moved, with their
                // label and size as they stood.
                foreach ($row['containers'] as $containerRow) {
                    BranchTransferLineContainer::query()->create([
                        'branch_transfer_line_id' => $transferLine->id,
                        'company_id' => $companyId,
                        'container_id' => $containerRow['container']->id,
                        'container_label' => ContainerAmount::label($ingredient, $containerRow['container']),
                        'container_factor' => (string) $containerRow['container']->factor,
                        'pieces' => (string) $containerRow['pieces'],
                    ]);
                }

                // Out of source (negative), into destination (positive). Both
                // reference this transfer so the ledger links back to it.
                $outLeg = $this->writeMovement->handle(
                    branch: $from,
                    ingredient: $ingredient,
                    type: StockMovementType::TransferOut,
                    quantity: -$quantity,
                    unitCostAtTime: $unitCost,
                    referenceType: BranchTransfer::class,
                    referenceId: $transfer->id,
                    actor: $actor,
                    note: $note,
                );
                $inLeg = $this->writeMovement->handle(
                    branch: $to,
                    ingredient: $ingredient,
                    type: StockMovementType::TransferIn,
                    quantity: $quantity,
                    unitCostAtTime: $unitCost,
                    referenceType: BranchTransfer::class,
                    referenceId: $transfer->id,
                    actor: $actor,
                    note: $note,
                );

                // B3 — the breakdown moves with the stock (leaf containers).
                if ($row['containers'] !== []) {
                    $this->breakdown->move($ingredient, (int) $from->id, (int) $to->id, ContainerBreakdownAction::leaves($ingredient, $row['containers']), 'transfer_out', 'transfer_in', $actor, [
                        'reference_type' => BranchTransfer::class,
                        'reference_id' => (int) $transfer->id,
                        'stock_movement_id' => (int) $outLeg->id,
                        'to_stock_movement_id' => (int) $inLeg->id,
                    ]);
                }
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.transfer.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $from->id,
                auditableType: BranchTransfer::class,
                auditableId: $transfer->id,
                newValues: [
                    'from_branch_id' => $from->id,
                    'to_branch_id' => $to->id,
                    'line_count' => count($resolved),
                    'note' => $note,
                ],
            ));

            return $transfer->fresh(['lines.ingredient', 'fromBranch', 'toBranch']);
        });
    }
}
