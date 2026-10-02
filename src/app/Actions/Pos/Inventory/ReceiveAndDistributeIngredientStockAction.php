<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\StockDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G4 — receive a bulk ingredient purchase AND split it across branches in
 * ONE step ("100 kg in: 20 to A, 20 to B, 25 to C — the rest stays in the
 * warehouse"), the ingredient twin of {@see ReceiveAndDistributeProductStockAction}.
 * Composes Receive (credit the warehouse) + Allocate (fan out to branches)
 * inside a single transaction, so the whole receive-and-distribute lands
 * together or not at all.
 *
 * Guard: the distributed total may not exceed the received quantity. Checked
 * up front (pure arithmetic on the inputs) before touching the DB, so an
 * over-distribution writes nothing. The inner Allocate still enforces its own
 * "<= central balance" guard under a row lock, which holds trivially here
 * since the receive credits exactly `total` first.
 *
 * LAUNCH-P2 — the receive stamps the price paid and updates the weighted
 * average; the allocation legs move at the NEW average and carry the same
 * date and reference as the receive (a back-dated receipt dates every leg).
 */
final readonly class ReceiveAndDistributeIngredientStockAction
{
    public function __construct(
        private ReceiveIngredientStockAction $receive,
        private AllocateIngredientStockAction $allocate,
    ) {}

    /**
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $lines
     * @param  array<string, mixed>  $costContext
     * @return array{received: StockMovement, allocations: list<StockMovement>, expense: Expense|null, unit_cost: string}
     */
    public function handle(
        Ingredient $ingredient,
        string|float|int $total,
        array $lines,
        ?string $note,
        User $actor,
        string|float|int|null $totalCost = null,
        string|float|int|null $deliveryCost = null,
        // PD6 — the accounting date forwarded to the receive's booked
        // expenses (the Goods Received Note's received_at). NULL = now.
        ?Carbon $occurredAt = null,
        // PT — tax paid on the item cost, forwarded to the receive.
        string|float|int|null $taxAmount = null,
        string|float|int|null $taxRate = null,
        // LAUNCH-P2 — see ReceiveIngredientStockAction::receive().
        ?Carbon $movementAt = null,
        string|float|int|null $paidUnitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $costContext = [],
    ): array {
        $totalQty = (float) $total;
        if ($totalQty <= 0) {
            throw new RuntimeException('Received quantity must be greater than zero.');
        }

        $distributed = 0.0;
        foreach ($lines as $line) {
            $distributed += (float) $line['quantity'];
        }
        if ($distributed > $totalQty + 1e-9) {
            throw new RuntimeException(sprintf(
                'You are distributing %s but only received %s. Distribute at most the received total.',
                StockDecimal::quantity($distributed),
                StockDecimal::quantity($totalQty),
            ));
        }

        return DB::transaction(function () use (
            $ingredient, $total, $lines, $note, $actor, $totalCost, $deliveryCost, $occurredAt, $taxAmount, $taxRate,
            $movementAt, $paidUnitCost, $referenceType, $referenceId, $costContext
        ): array {
            // PD5 — the cost/delivery ride the single receive into the central
            // warehouse (one purchase = one expense pair); the allocations are
            // pure internal movement, no expense.
            $received = $this->receive->receive(
                $ingredient, $total, $note, $actor, $totalCost, $deliveryCost, $occurredAt, $taxAmount, $taxRate,
                movementAt: $movementAt,
                paidUnitCost: $paidUnitCost,
                referenceType: $referenceType,
                referenceId: $referenceId,
                costContext: $costContext,
            );
            $allocations = $lines === []
                ? []
                : $this->allocate->handle(
                    $ingredient, $lines, $note, $actor,
                    occurredAt: $movementAt ?? $occurredAt,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                );

            return [
                'received' => $received['movement'],
                'allocations' => $allocations,
                'expense' => $received['expense'],
                'unit_cost' => $received['unit_cost'],
            ];
        });
    }
}
