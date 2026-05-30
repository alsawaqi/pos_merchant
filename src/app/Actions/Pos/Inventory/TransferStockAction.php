<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\StockMovementType;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferLine;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\MerchantTenantContext;
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
    ) {}

    /**
     * @param  list<array{ingredient_uuid: string, quantity: string|float|int}>  $lines
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
            if (isset($seen[$ingredient->id])) {
                throw new RuntimeException('Ingredient "'.$ingredient->name.'" is listed more than once.');
            }
            $seen[$ingredient->id] = true;

            $quantity = (float) $line['quantity'];
            if ($quantity <= 0) {
                throw new RuntimeException('Transfer quantity for "'.$ingredient->name.'" must be positive.');
            }

            $available = (float) ($ingredient->branchStock()->where('branch_id', $from->id)->value('quantity') ?? 0);
            if ($quantity > $available) {
                throw new RuntimeException(sprintf(
                    'Not enough "%s" at the source branch: have %s, transferring %s.',
                    $ingredient->name,
                    rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                ));
            }

            $resolved[] = ['ingredient' => $ingredient, 'quantity' => $quantity];
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

                BranchTransferLine::query()->create([
                    'branch_transfer_id' => $transfer->id,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => (string) $quantity,
                    'unit_at_set' => $ingredient->unit->value,
                    'unit_cost_at_time' => (string) $unitCost,
                ]);

                // Out of source (negative), into destination (positive). Both
                // reference this transfer so the ledger links back to it.
                $this->writeMovement->handle(
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
                $this->writeMovement->handle(
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
