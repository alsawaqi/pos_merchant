<?php

declare(strict_types=1);

namespace App\Actions\Pos\Discounts;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\DiscountScope;
use App\Enums\DiscountTargetType;
use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 6d — sync a discount's targets list (PUT semantics).
 *
 * Payload shape:
 *   [
 *     ['target_type' => 'product', 'target_id' => 42],
 *     ['target_type' => 'category', 'target_id' => 7],
 *     ...
 *   ]
 *
 * Tenant invariants enforced:
 *   - discount belongs to actor's company (404 otherwise)
 *   - every target_id resolves to a row in the right table
 *     AND belongs to the actor's company
 *   - dedup on (target_type, target_id)
 *
 * scope rules:
 *   - product or category scope discounts: targets list is
 *     allowed (empty means "rule won't apply to anything",
 *     which is a degenerate state but the merchant might
 *     want it temporarily during reconfiguration)
 *   - order scope discounts: targets list MUST be empty —
 *     the rule applies unconditionally
 *
 * Idempotent: same shape in → no audit row written.
 *
 * Audit event: catalogue.discount.targets_synced (when targets
 * actually change).
 */
final readonly class SetDiscountTargetsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, array{target_type: string, target_id: int}>  $targets
     */
    public function handle(Discount $discount, array $targets, User $actor): Discount
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $discount->company_id !== $companyId) {
            abort(404);
        }

        // Order-scope discounts can't have targets.
        if ($discount->scope === DiscountScope::Order && $targets !== []) {
            throw new RuntimeException('Order-scope discounts cannot have targets.');
        }

        // Dedup on (type, id).
        $seen = [];
        $clean = [];
        foreach ($targets as $row) {
            $type = DiscountTargetType::from((string) $row['target_type']);
            $id = (int) $row['target_id'];
            $key = $type->value . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = ['target_type' => $type, 'target_id' => $id];
        }

        // Cross-tenant resolution: every product / category id
        // MUST belong to the actor's company.
        $productIds = [];
        $categoryIds = [];
        foreach ($clean as $row) {
            if ($row['target_type'] === DiscountTargetType::Product) {
                $productIds[] = $row['target_id'];
            } else {
                $categoryIds[] = $row['target_id'];
            }
        }

        if ($productIds !== []) {
            $okProducts = Product::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $productIds)
                ->count();
            if ($okProducts !== count($productIds)) {
                throw new RuntimeException('One or more product targets do not belong to your company.');
            }
        }
        if ($categoryIds !== []) {
            $okCategories = ProductCategory::query()
                ->where('company_id', $companyId)
                ->whereIn('id', $categoryIds)
                ->count();
            if ($okCategories !== count($categoryIds)) {
                throw new RuntimeException('One or more category targets do not belong to your company.');
            }
        }

        // Idempotent skip: same shape as currently stored.
        $currentShape = $discount->targets()->get(['target_type', 'target_id'])
            ->map(static fn (DiscountTarget $t): string => $t->target_type->value . ':' . $t->target_id)
            ->sort()
            ->values()
            ->all();
        $newShape = collect($clean)
            ->map(static fn (array $t): string => $t['target_type']->value . ':' . $t['target_id'])
            ->sort()
            ->values()
            ->all();
        if ($currentShape === $newShape) {
            return $discount->fresh();
        }

        return DB::transaction(function () use ($discount, $clean, $actor, $companyId, $currentShape, $newShape): Discount {
            $discount->targets()->delete();
            foreach ($clean as $row) {
                DiscountTarget::query()->create([
                    'discount_id' => $discount->id,
                    'target_type' => $row['target_type']->value,
                    'target_id' => $row['target_id'],
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.discount.targets_synced',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Discount::class,
                auditableId: $discount->id,
                oldValues: ['targets' => $currentShape],
                newValues: ['targets' => $newShape],
            ));

            return $discount->fresh();
        });
    }
}
