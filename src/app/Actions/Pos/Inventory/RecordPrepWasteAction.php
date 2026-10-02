<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\WasteReason;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\WasteRecord;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\RecipeQuantity;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P3 P3-4 — waste of a PREP ITEM ("1 L of sauce thrown away").
 *
 * A prep item has no stock of its own: the sauce was made from raw
 * ingredients the books still hold (only a sale deducts them). So the waste
 * explodes the prep item by its recipe ({@see PrepGraph::explode()}) and
 * records the waste of each raw ingredient at its CURRENT cost — one waste
 * record + one waste movement per raw ingredient (the existing
 * {@see RecordWasteAction}, so the ledger, the stock check and the per-line
 * audit behave exactly like any ingredient waste) — all in ONE transaction,
 * as ONE event naming the prep item:
 *
 *   - every record's notes start "Prep item: <name>, <amount>" (+ the notes);
 *   - one `inventory.waste.prep_recorded` audit row lists the prep item, the
 *     amount, every exploded line and the total cost.
 *
 * Like any waste it is refused when a raw ingredient's branch stock cannot
 * absorb it (the message names the ingredient). A stock-count reconciliation
 * reason is refused: counts never include prep items.
 */
final readonly class RecordPrepWasteAction
{
    public function __construct(
        private RecordWasteAction $recordWaste,
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private RecipeQuantity $quantities,
    ) {}

    /**
     * @return array{records: Collection<int, WasteRecord>, total_cost: string, quantity: string}
     */
    public function handle(
        Branch $branch,
        Ingredient $prep,
        string|float|int $quantity,
        WasteReason $reason,
        User $actor,
        ?string $notes = null,
        ?DateTimeInterface $occurredAt = null,
        ?string $unit = null,
    ): array {
        $companyId = $this->tenant->requiredId();
        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }
        if ((int) $prep->company_id !== $companyId || ! $prep->isPrep()) {
            throw new RuntimeException('Prep item not found.');
        }
        if ($reason === WasteReason::ReconciliationVariance) {
            throw new RuntimeException('A stock count never includes prep items — record the waste with another reason.');
        }
        if ($reason === WasteReason::Other && trim((string) $notes) === '') {
            throw new RuntimeException("Notes are required when reason is 'other'.");
        }

        $entered = $this->quantities->resolve($prep, $quantity, $unit);
        $raw = PrepGraph::load($companyId)->explode([(int) $prep->id => $entered['quantity']]);
        if ($raw === []) {
            throw new RuntimeException(sprintf('"%s" has no ingredients to waste — check its recipe.', $prep->name));
        }

        /** @var Collection<int, Ingredient> $ingredients */
        $ingredients = Ingredient::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($raw))
            ->get()
            ->keyBy('id');

        $amount = $entered['entered_quantity'].' '.$this->quantities->label($prep, $entered['entered_unit']);

        // The same "do not waste what the books do not hold" rule as an
        // ingredient waste — checked up front so the message names the
        // ingredient and nothing is half-written.
        $balances = DB::table('pos_branch_stock')
            ->where('branch_id', $branch->id)
            ->whereIn('ingredient_id', array_keys($raw))
            ->pluck('quantity', 'ingredient_id');
        foreach ($raw as $ingredientId => $qty) {
            $held = (string) ($balances[$ingredientId] ?? '0');
            if (BigDecimal::of($held)->isLessThan($qty)) {
                $ingredient = $ingredients->get($ingredientId);
                throw new RuntimeException(sprintf(
                    'Not enough %s to waste %s of %s: the branch holds %s %s but the prep item needs %s %s.',
                    $ingredient?->name ?? '#'.$ingredientId,
                    $amount,
                    $prep->name,
                    StockDecimal::quantity($held),
                    $ingredient?->unit?->value ?? '',
                    $qty,
                    $ingredient?->unit?->value ?? '',
                ));
            }
        }

        $label = sprintf('Prep item: %s, %s', $prep->name, $amount);
        $combinedNotes = trim((string) $notes) !== '' ? $label.' — '.trim((string) $notes) : $label;
        $occurredAt = $occurredAt instanceof DateTimeInterface ? Carbon::instance($occurredAt) : now();

        return DB::transaction(function () use ($branch, $prep, $raw, $ingredients, $reason, $actor, $combinedNotes, $occurredAt, $entered, $amount, $notes, $companyId): array {
            $records = collect();
            $lines = [];
            $total = BigDecimal::zero();
            foreach ($raw as $ingredientId => $qty) {
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients->get($ingredientId);
                $record = $this->recordWaste->handle(
                    branch: $branch,
                    ingredient: $ingredient,
                    quantity: $qty,
                    reason: $reason,
                    actor: $actor,
                    notes: $combinedNotes,
                    occurredAt: $occurredAt,
                );
                $records->push($record);
                $cost = BigDecimal::of((string) $record->quantity)->multipliedBy((string) $record->unit_cost_at_time);
                $total = $total->plus($cost);
                $lines[] = [
                    'ingredient_id' => (int) $ingredient->id,
                    'ingredient_name' => $ingredient->name,
                    'quantity' => (string) $record->quantity,
                    'unit' => $ingredient->unit?->value,
                    'unit_cost_at_time' => (string) $record->unit_cost_at_time,
                    'cost' => (string) $cost->toScale(3, RoundingMode::HALF_UP),
                    'waste_record_id' => (int) $record->id,
                ];
            }

            $totalCost = (string) $total->toScale(3, RoundingMode::HALF_UP);
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.waste.prep_recorded',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: Ingredient::class,
                auditableId: $prep->id,
                newValues: [
                    'prep_item_id' => (int) $prep->id,
                    'prep_item_name' => $prep->name,
                    'amount' => $amount,
                    'quantity' => $entered['quantity'],
                    'unit' => $prep->unit?->value,
                    'reason' => $reason->value,
                    'notes' => $notes,
                    'lines' => $lines,
                    'total_cost' => $totalCost,
                ],
            ));

            return ['records' => $records, 'total_cost' => $totalCost, 'quantity' => $entered['quantity']];
        });
    }
}
