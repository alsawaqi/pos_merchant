<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\RestockRequestStatus;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\RestockRequest;
use App\Models\RestockRequestLine;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5c — create a new restock request in Draft state.
 *
 * Caller passes the requesting branch + an array of lines
 * (ingredient_uuid + quantity_requested + optional note).
 * The action:
 *
 *   1. Confirms branch is in the actor's tenant.
 *   2. Dedupes the line ingredients (same ingredient twice in
 *      one request → 422, caller should sum).
 *   3. Resolves every ingredient_uuid → id in one query,
 *      tenant-scoped. Any bogus / cross-tenant uuid aborts
 *      the whole creation.
 *   4. Writes the parent + lines in one transaction.
 *   5. Emits inventory.restock_request.created audit row.
 *
 * Empty lines array is rejected — an empty request is
 * meaningless. The merchant should just not create one.
 *
 * Status always starts at Draft. Use SubmitRestockRequestAction
 * to advance to Submitted.
 */
final readonly class CreateRestockRequestAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private IngredientUnitConverter $units,
    ) {}

    /**
     * @param  array<int, array{ingredient_uuid: string, quantity_requested: numeric-string|float|int, unit?: ?string, note?: ?string}>  $lines
     */
    public function handle(Branch $branch, array $lines, User $actor, ?string $note = null): RestockRequest
    {
        $companyId = $this->tenant->requiredId();

        if ((int) $branch->company_id !== $companyId) {
            abort(404);
        }

        if ($lines === []) {
            throw new RuntimeException('A restock request must contain at least one line.');
        }

        // Dedupe — same ingredient at most once per request.
        $uuids = array_map(static fn (array $l): string => (string) $l['ingredient_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Duplicate ingredient in restock request — merge them client-side first.');
        }

        // Resolve uuids → ingredients in one tenant-scoped query.
        // Any uuid that doesn't resolve (bogus or cross-tenant)
        // breaks the count and aborts.
        $ingredients = Ingredient::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($ingredients->count() !== count($uuids)) {
            throw new RuntimeException('One or more ingredients in the request do not belong to your company.');
        }

        return DB::transaction(function () use (
            $branch,
            $lines,
            $ingredients,
            $actor,
            $note,
            $companyId,
        ): RestockRequest {
            /** @var RestockRequest $request */
            $request = RestockRequest::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branch->id,
                'status' => RestockRequestStatus::Draft->value,
                'requested_by_user_id' => $actor->getKey(),
                'note' => $note,
            ]);

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
                event: 'inventory.restock_request.created',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                branchId: $branch->id,
                auditableType: RestockRequest::class,
                auditableId: $request->id,
                newValues: [
                    'line_count' => count($lines),
                    'ingredient_ids' => array_values($ingredients->pluck('id')->all()),
                ],
            ));

            return $request->fresh(['lines.ingredient', 'branch']);
        });
    }
}
