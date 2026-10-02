<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\PrepGraph;
use App\Support\Recipes\PrepUsage;
use App\Support\Recipes\RecipeEditGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P3 P3-4 — soft-delete a prep item that nothing uses any more.
 *
 * Refused while a product recipe, an add-on option or another (live) prep
 * item still uses it: the sale pipeline would keep exploding it. Its recipe
 * lines are KEPT (soft delete): an older product recipe version may still
 * name it, and pos_api must be able to explode that version for a sale made
 * before the edit (P3-6). Needs "Edit recipes".
 */
final readonly class DeletePrepItemAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(Ingredient $prep, User $actor): void
    {
        RecipeEditGate::ensure($actor);
        $companyId = $this->tenant->requiredId();
        if ((int) $prep->company_id !== $companyId || ! $prep->isPrep()) {
            abort(404);
        }

        DB::transaction(function () use ($prep, $actor, $companyId): void {
            // Serialised with prep recipe saves (SavePrepItemAction locks the
            // same row), so no save can start using it while it goes.
            DB::table('pos_companies')->where('id', $companyId)->lockForUpdate()->first(['id']);
            $usage = PrepUsage::of($prep);
            if ($usage->total() > 0) {
                throw new RuntimeException(sprintf(
                    'Cannot delete prep item "%s" — %s still use it. Edit those first.',
                    $prep->name,
                    $usage->describe(),
                ));
            }

            $prep->delete();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.prep_item.deleted',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Ingredient::class,
                auditableId: $prep->id,
                oldValues: [
                    'name' => $prep->name,
                    'unit' => $prep->unit?->value,
                    'prep_yield_quantity' => (string) $prep->prep_yield_quantity,
                ],
            ));
        });

        PrepGraph::forget($companyId);
    }
}
