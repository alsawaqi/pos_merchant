<?php

declare(strict_types=1);

namespace App\Actions\Pos\Catalogue;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\User;
use App\Support\Catalogue\OrderTypes;
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
 *   - a product can't be its own component;
 *   - identical shape on disk = no writes, no audit.
 *
 * LAUNCH packaging add-on — each row carries its "Used for" ticks
 * (`order_types`, {@see OrderTypes}; pos_api takes a row only for the
 * order types it is ticked for). The same item may sit on several rows
 * whose ticks do not overlap (tester call 3: cup for to go + delivery, mug
 * for dine in is two items; napkin ×1 dine in + napkin ×3 to go is one item
 * on two rows); overlapping rows are a 422, as is a row with no tick. A row
 * sent without `order_types` (an old tab) keeps the stored ticks of that
 * item's only row. A change is audited and touches the product (the device
 * config delta key).
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
     * @param  array<int, array{component_uuid: string, quantity: numeric-string|float|int, order_types?: ?int}>  $lines
     */
    public function handle(Product $product, array $lines, User $actor): Product
    {
        $companyId = $this->tenant->requiredId();
        if ((int) $product->company_id !== $companyId) {
            abort(404);
        }

        // PD3a — a physical item consumes nothing itself (a cup has no
        // components); only sellable products carry composition.
        if ($product->is_internal && $lines !== []) {
            throw new RuntimeException('A physical item cannot consume other items.');
        }
        // LAUNCH-P4 B2 — a combo has no components: each chosen item uses
        // its own recipe and packaging when the combo sells.
        if ($product->isCombo() && $lines !== []) {
            throw new RuntimeException('A combo has no components: each item in it uses its own recipe and packaging.');
        }

        $uuids = array_values(array_unique(array_map(static fn (array $l): string => (string) $l['component_uuid'], $lines)));

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
            // PD3b — components are piece-counted things: unit products
            // (packaging, bought-in) or PREPARED cooked products (a patty
            // inside a burger — its shelf stock decrements at sale; its
            // own recipe was already consumed at production time).
            if (! in_array($component->stock_mode, ['unit', 'cooked'], true)) {
                throw new RuntimeException(sprintf(
                    '"%s" is not piece-counted — a component must be Ready / bought-in or a prepared (Cooked) product.',
                    $component->name,
                ));
            }
            // PD3a — branch-use items (bulbs, cleaning) are never
            // attachable to food. Legacy NULL = packaging stays allowed,
            // as do pre-PD3a sellable-unit attachments (the picker no
            // longer offers new ones, but re-saving a legacy product
            // must not break).
            if ($component->internal_purpose === 'general') {
                throw new RuntimeException(sprintf(
                    '"%s" is a branch-use physical item — it cannot be attached to a product.',
                    $component->name,
                ));
            }
        }

        $current = $product->components()->get();
        $stored = [];
        foreach ($current as $row) {
            $stored[(string) $row->component_product_id][] = OrderTypes::read($row->order_types);
        }

        // The new rows with their ticks (as sent, or the stored ones).
        $rows = [];
        foreach ($lines as $l) {
            $component = $componentProducts[$l['component_uuid']];
            $rows[] = [
                'component_id' => (int) $component->id,
                'name' => (string) $component->name,
                'quantity' => number_format((float) $l['quantity'], 3, '.', ''),
                'order_types' => OrderTypes::resolve($l['order_types'] ?? null, (string) $component->id, $stored),
            ];
        }
        OrderTypes::assertNoOverlap(array_map(static fn (array $r): array => [
            'key' => (string) $r['component_id'],
            'mask' => $r['order_types'],
            'name' => $r['name'],
        ], $rows));

        // Normalised shapes for the no-op diff and the audit: "id" (every
        // order type, as before the add-on) or "id:mask" => quantity.
        $newShape = collect($rows)->mapWithKeys(static fn (array $r): array => [
            self::shapeKey($r['component_id'], $r['order_types']) => $r['quantity'],
        ])->sortKeys();

        $currentShape = $current
            ->mapWithKeys(static fn (ProductComponent $c): array => [
                self::shapeKey((int) $c->component_product_id, OrderTypes::read($c->order_types)) => number_format((float) $c->quantity, 3, '.', ''),
            ])->sortKeys();

        if ($newShape->toArray() === $currentShape->toArray()) {
            return $product->fresh(['components.component']);
        }

        return DB::transaction(function () use ($product, $rows, $newShape, $actor, $companyId, $currentShape): Product {
            $product->components()->delete();
            foreach ($rows as $row) {
                ProductComponent::query()->create([
                    'product_id' => $product->id,
                    'component_product_id' => $row['component_id'],
                    'quantity' => $row['quantity'],
                    'order_types' => $row['order_types'],
                ]);
            }

            // LAUNCH packaging add-on — the device config re-sends a product
            // only when its updated_at moves.
            $product->touch();

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

    private static function shapeKey(int $componentId, int $orderTypes): string
    {
        return $orderTypes === OrderTypes::ALL ? (string) $componentId : $componentId.':'.$orderTypes;
    }
}
