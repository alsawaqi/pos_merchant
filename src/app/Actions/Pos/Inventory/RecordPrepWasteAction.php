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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 *   - fix order 1, K4: every record carries the prep item and one shared
 *     waste_group_uuid, so Loss & Waste counts ONE event naming the prep;
 *   - one `inventory.waste.prep_recorded` audit row lists the prep item, the
 *     amount, every exploded line and the total cost.
 *
 * Fix order 1, K3 — like every waste it follows the selling rule (owner
 * decision 2026-10-02): it is never refused on the stock numbers. A raw
 * ingredient the branch does not hold enough of (or never received: water,
 * salt) goes below zero, and the response carries a warning naming each one.
 * A stock-count reconciliation reason is refused: counts never include prep
 * items.
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
     * @return array{records: Collection<int, WasteRecord>, total_cost: string, quantity: string, waste_group_uuid: string, warning: ?string}
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

        $label = sprintf('Prep item: %s, %s', $prep->name, $amount);
        $combinedNotes = trim((string) $notes) !== '' ? $label.' — '.trim((string) $notes) : $label;
        $occurredAt = $occurredAt instanceof DateTimeInterface ? Carbon::instance($occurredAt) : now();
        $group = (string) Str::uuid();

        return DB::transaction(function () use ($branch, $prep, $raw, $ingredients, $reason, $actor, $combinedNotes, $occurredAt, $entered, $amount, $notes, $companyId, $group): array {
            $records = collect();
            $lines = [];
            $short = [];
            $total = BigDecimal::zero();
            foreach ($raw as $ingredientId => $qty) {
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients->get($ingredientId);
                $written = $this->recordWaste->record(
                    branch: $branch,
                    ingredient: $ingredient,
                    quantity: $qty,
                    reason: $reason,
                    actor: $actor,
                    notes: $combinedNotes,
                    occurredAt: $occurredAt,
                    prepIngredientId: (int) $prep->id,
                    wasteGroupUuid: $group,
                );
                $record = $written['record'];
                if (BigDecimal::of($written['balance_after'])->isNegative()) {
                    $unit = (string) ($ingredient->unit?->value ?? '');
                    $short[] = sprintf('%s held %s %s and this waste used %s %s, so it is now %s %s', $ingredient->name, $written['balance_before'], $unit, $qty, $unit, $written['balance_after'], $unit);
                }
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
                    'waste_group_uuid' => $group,
                ],
            ));

            $warning = $short === [] ? null : sprintf(
                'Recorded. Below zero at this branch now: %s. Count them or receive stock to correct it.',
                implode('; ', $short),
            );

            return ['records' => $records, 'total_cost' => $totalCost, 'quantity' => $entered['quantity'], 'waste_group_uuid' => $group, 'warning' => $warning];
        });
    }
}
