<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Models\Ingredient;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c — replace the lines of a Draft restock request.
 *
 * Idempotent, same pattern as UpdateProductRecipeAction:
 *   1. Refuses if the request isn't in Draft state — once a
 *      requester submits, the lines are locked.
 *   2. Resolves all ingredient_uuids tenant-scoped + dedupe.
 *   3. Compares the new shape to disk — identical = no-op skip
 *      (no audit, no DB churn).
 *   4. Wipes + re-inserts the lines + writes one audit row.
 *
 * Empty array is allowed at this layer but the controller-level
 * validation rejects it (a request needs at least one line to
 * submit). The action stays tolerant so a future "save as
 * empty draft" UX wouldn't require a code change here.
 *
 * The parent request's note can be updated too — pass it via
 * the $note parameter (null = leave unchanged).
 */
final readonly class UpdateRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  array<int, array{ingredient_uuid: string, quantity_requested: numeric-string|float|int, unit?: ?string, note?: ?string}>  $lines
     */
    public function handle(RestockRequest $request, array $lines, User $actor, ?string $note = null): RestockRequest
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $request->company_id !== $companyId) {
            abort(404);
        }

        // Only Draft requests can have their lines edited. Once
        // submitted, the requester must cancel + create a new
        // one (or HQ has to reject + restart).
        if ($request->status !== RestockRequestStatus::Draft) {
            throw new RuntimeException(sprintf(
                'Only Draft requests can be edited (current status: %s).',
                $request->status->value,
            ));
        }

        $uuids = array_map(static fn (array $l): string => (string) $l['ingredient_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Duplicate ingredient in restock request — merge them client-side first.');
        }

        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($ingredients->count() !== count($uuids)) {
            throw new RuntimeException('One or more ingredients in the request do not belong to your company.');
        }

        // Diff comparison — same ingredients + same quantities (compared in BASE
        // units, so an alt-unit re-entry that resolves to the same base is a
        // no-op) + same parent note → no-op skip.
        $newShape = collect($lines)->mapWithKeys(function (array $l) use ($ingredients): array {
            /** @var Ingredient $ing */
            $ing = $ingredients[$l['ingredient_uuid']];
            $qty = $this->units->toBase($ing, $l['quantity_requested'], $l['unit'] ?? null);
            return [$ing->id => number_format($qty, 3, '.', '')];
        });
        $currentShape = $request->lines()
            ->get(['ingredient_id', 'quantity_requested'])
            ->mapWithKeys(static fn (RestockRequestLine $r): array => [
                (int) $r->ingredient_id => (string) $r->quantity_requested,
            ]);

        $noteUnchanged = $note === null || $note === $request->note;

        if ($this->shapesEqual($currentShape, $newShape) && $noteUnchanged) {
            return $request->fresh(['lines.ingredient', 'branch']);
        }

        return DB::transaction(function () use (
            $request,
            $lines,
            $ingredients,
            $actor,
            $note,
            $currentShape,
            $newShape,
            $companyId,
        ): RestockRequest {
            // Update parent note if provided.
            if ($note !== null) {
                $request->forceFill(['note' => $note])->save();
            }

            $request->lines()->delete();
            foreach ($lines as $idx => $line) {
                /** @var Ingredient $ing */
                $ing = $ingredients[$line['ingredient_uuid']];
                // #13 — store the requested amount in the ingredient's base unit.
                $qty = $this->units->toBase($ing, $line['quantity_requested'], $line['unit'] ?? null);
                if ($qty <= 0) {
                    throw new RuntimeException('Each line quantity_requested must be positive.');
                }
                RestockRequestLine::query()->create([
                    'restock_request_id' => $request->id,
                    'ingredient_id' => $ing->id,
                    'quantity_requested' => number_format($qty, 3, '.', ''),
                    'quantity_allocated' => '0.000',
                    'unit_at_set' => $ing->unit?->value,
                    'note' => $line['note'] ?? null,
                    'sort_order' => $idx,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.restock_request.updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $request->branch_id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                oldValues: [
                    'line_count' => $currentShape->count(),
                    'ingredient_ids' => $currentShape->keys()->all(),
                ],
                newValues: [
                    'line_count' => $newShape->count(),
                    'ingredient_ids' => $newShape->keys()->all(),
                ],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }

    /**
     * @param  Collection<int, string>  $a
     * @param  Collection<int, string>  $b
     */
    private function shapesEqual(Collection $a, Collection $b): bool
    {
        if ($a->count() !== $b->count()) {
            return false;
        }
        foreach ($a as $ingredientId => $qty) {
            if (! $b->has($ingredientId)) {
                return false;
            }
            if ((string) $b[$ingredientId] !== (string) $qty) {
                return false;
            }
        }
        return true;
    }
}
