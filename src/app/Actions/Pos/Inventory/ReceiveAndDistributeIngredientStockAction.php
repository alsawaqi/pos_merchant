<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\User;
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
 */
final readonly class ReceiveAndDistributeIngredientStockAction
{
    public function __construct(
        private ReceiveIngredientStockAction $receive,
        private AllocateIngredientStockAction $allocate,
    ) {}

    /**
     * @param  list<array{branch: Branch, quantity: string|float|int}>  $lines
     * @return array{received: StockMovement, allocations: list<StockMovement>}
     */
    public function handle(
        Ingredient $ingredient,
        string|float|int $total,
        array $lines,
        ?string $note,
        User $actor,
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
                'You are distributing %.3f but only received %.3f. Distribute at most the received total.',
                $distributed,
                $totalQty,
            ));
        }

        return DB::transaction(function () use ($ingredient, $total, $lines, $note, $actor): array {
            $received = $this->receive->handle($ingredient, $total, $note, $actor);
            $allocations = $lines === []
                ? []
                : $this->allocate->handle($ingredient, $lines, $note, $actor);

            return ['received' => $received, 'allocations' => $allocations];
        });
    }
}
