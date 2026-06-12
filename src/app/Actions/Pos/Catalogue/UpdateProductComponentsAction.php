<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * P-G2 — atomically replace a product's physical-item components
 * (coffee = 1 x cup 12oz + 1 x lid), the UpdateProductRecipeAction
 * pattern without versioning (components carry no cost history).
 *
 * Idempotent: caller PUTs the full desired set. Guards:
 *   - every component resolves to a product of the SAME company;
 *   - components must be UNIT-mode products (the piece-counted world
 *     the central pool / Receive & Distribute machinery manages);
 *   - a product can't be its own component; duplicates are a 422
 *     (merge client-side);
 *   - identical shape on disk = no writes, no audit.
 *
 * Empty array = "consumes no physical items" — a valid terminal state.
 * pos_api consumes the components at order.pay (one level only:
 * components have no components).
 *
 * Audit event: catalogue.product.components_updated.
 */
final readonly class UpdateProductComponentsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<int, array{component_uuid: string, quantity: numeric-string|float|int}>  $lines
     */
    public function handle(Product $product, array $lines, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        $uuids = array_map(static fn (array $l): string => (string) $l['component_uuid'], $lines);
        if (count($uuids) !== count(array_unique($uuids))) {
            throw new RuntimeException('Duplicate component in payload — merge them client-side first.');
        }

        $componentProducts = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy('uuid');
        if ($componentProducts->count() !== count($uuids)) {
            throw new RuntimeException('One or more components do not belong to your company.');
        }

        foreach ($componentProducts as $component) {
            if ((int) $component->id === (int) $product->id) {
                throw new RuntimeException('A product cannot be its own component.');
            }
            if ($component->stock_mode !== 'unit') {
                throw new RuntimeException(sprintf(
                    '"%s" is not a unit-tracked product — physical items must be piece-counted (set it to Ready / bought-in first).',
                    $component->name,
                ));
            }
        }

        // Normalised new shape for the no-op diff.
        $newShape = collect($lines)->mapWithKeys(function (array $l) use ($componentProducts): array {
            $component = $componentProducts[$l['component_uuid']];

            return [(int) $component->id => number_format((float) $l['quantity'], 3, '.', '')];
        })->sortKeys();

        $currentShape = $product->components()
            ->get()
            ->mapWithKeys(static fn (ProductComponent $c): array => [
                (int) $c->component_product_id => number_format((float) $c->quantity, 3, '.', ''),
            ])->sortKeys();

        if ($newShape->toArray() === $currentShape->toArray()) {
            return $product->fresh(['components.component']);
        }

        return DB::transaction(function () use ($product, $newShape, $actor, $companyId, $currentShape): Product {
            $product->components()->delete();
            foreach ($newShape as $componentId => $quantity) {
                ProductComponent::query()->create([
                    'product_id' => $product->id,
                    'component_product_id' => $componentId,
                    'quantity' => $quantity,
                ]);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'catalogue.product.components_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Product::class,
                auditableId: $product->id,
                oldValues: ['components' => $currentShape->toArray()],
                newValues: ['components' => $newShape->toArray()],
            ));

            return $product->fresh(['components.component']);
        });
    }
}
