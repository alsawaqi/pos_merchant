<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\IngredientAltUnit;
use App\Models\ItemBarcode;
use App\Models\User;
use App\Support\Inventory\CountContainerMirror;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #13 — soft-delete an alternate unit. Removing it never breaks history:
 * every document snapshots the container's label and size, recipe lines keep
 * their stored base quantity; future entries simply can't pick it.
 *
 * LAUNCH review add-on:
 *   A2 a container another live container holds ("crate holds 12 × bottle")
 *      cannot go first — remove the crate, then the bottle. Its barcodes stop
 *      scanning (soft-deleted with it). A new container of the same name is a
 *      new row (no restore-by-name).
 *   A3 removing the count container clears count_container_id and its mirror:
 *      tills count the item in l / kg after their next settings refresh (the
 *      portal warns before it asks).
 *
 * Audit event: inventory.ingredient_unit.deleted.
 */
final readonly class DeleteIngredientUnitAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(IngredientAltUnit $unit, User $actor): void
    {
        $companyId = $this->tenant->requiredId();

        $holder = IngredientAltUnit::query()
            ->where('ingredient_id', $unit->ingredient_id)
            ->where('contains_unit_id', $unit->id)
            ->first();
        if ($holder !== null) {
            throw new RuntimeException(sprintf("'%s' holds this container — remove '%s' first.", $holder->name, $holder->name));
        }

        DB::transaction(function () use ($unit, $actor, $companyId): void {
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.ingredient_unit.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: IngredientAltUnit::class,
                auditableId: $unit->id,
                oldValues: ['name' => $unit->name, 'factor' => (string) $unit->factor],
            ));

            $ingredient = Ingredient::query()->find($unit->ingredient_id);
            if ($ingredient !== null && (int) ($ingredient->count_container_id ?? 0) === (int) $unit->id) {
                CountContainerMirror::apply($ingredient, null);
            }

            \App\Support\Inventory\ItemCodes::forgetBarcodes($companyId, ['container_id' => (int) $unit->id]);

            $unit->delete();
        });
    }
}
