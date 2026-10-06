<?php

declare(strict_types=1);

namespace App\Actions\Pos\Inventory;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\OrderPackagingLine;
use App\Models\Product;
use App\Models\ProductPack;
use App\Models\User;
use App\Support\Catalogue\OrderTypes;
use App\Support\Inventory\ContainerToken;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeQuantity;
use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH packaging add-on (owner decision 3) — replace the merchant's
 * per-order packaging list of ONE order type (Dine in, Quick order, To go or
 * Delivery). pos_api takes the list once per whole order, by the order's
 * final type, when it takes the order's stock.
 *
 * Each line is an ingredient XOR a physical item:
 *   - an ingredient (never a prep item: packaging is not cooked) in a unit of
 *     its kind — converted to its BASE unit and kept as typed, the recipe
 *     convention ({@see RecipeQuantity}); an amount that rounds to 0 is
 *     refused;
 *   - a physical item in pieces, or in one of its packs ("1 × box of 50"
 *     = 50 pieces, kept as typed with the pack's token): packaging physical
 *     items and bought-in products (piece-counted, never branch-use).
 * Everything must belong to the merchant (tenant). One line per item per
 * type. Changing the list needs "Edit recipes" (tester call 5; the same rule
 * as prep items and add-on stock lines); an unchanged list is a no-op with no
 * audit row. Rows are soft-deleted, and a re-added item brings its row back.
 *
 * Audit event: inventory.order_packaging_updated.
 */
final readonly class SaveOrderPackagingAction
{
    public const MAX_LINES = 30;

    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
        private RecipeQuantity $quantities,
    ) {}

    /**
     * @param  list<array{type: string, ingredient_uuid?: ?string, product_uuid?: ?string, quantity: numeric-string|float|int, unit?: ?string}>  $lines
     */
    public function handle(string $orderType, array $lines, User $actor): void
    {
        $companyId = $this->tenant->requiredId();
        if (! array_key_exists($orderType, OrderTypes::BUCKETS)) {
            abort(404);
        }
        if (count($lines) > self::MAX_LINES) {
            throw new RuntimeException('A packaging list can hold at most '.self::MAX_LINES.' items.');
        }

        $resolved = $this->resolve($lines, $companyId);

        $current = OrderPackagingLine::query()
            ->where('company_id', $companyId)
            ->where('order_type', $orderType)
            ->with(['ingredient', 'product'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        // A stored ingredient line compares as the page reopens it (its typed
        // unit while that still converts, else the base), so sending it back
        // untouched stays a no-op.
        $before = array_map(function (OrderPackagingLine $l): array {
            [$enteredUnit, $enteredQuantity] = [$l->entered_unit, $l->entered_quantity];
            if ($l->ingredient !== null) {
                $shown = $this->quantities->display($l->ingredient, (string) $l->quantity, $l->entered_unit, $l->entered_quantity);
                [$enteredUnit, $enteredQuantity] = [$shown['unit'], $shown['quantity']];
            }

            return $this->shape($l->ingredient_id, $l->product_id, (string) $l->quantity, $enteredUnit, $enteredQuantity, $l->ingredient?->name ?? $l->product?->name ?? '', $l->unit);
        }, $current->all());
        $after = array_map(fn (array $l): array => $this->shape($l['ingredient_id'], $l['product_id'], $l['quantity'], $l['entered_unit'], $l['entered_quantity'], $l['name'], $l['unit']), $resolved);

        if (array_column($before, 'key') === array_column($after, 'key')) {
            return;
        }

        RecipeEditGate::ensure($actor);

        DB::transaction(function () use ($orderType, $resolved, $companyId, $actor, $before, $after): void {
            OrderPackagingLine::query()
                ->where('company_id', $companyId)
                ->where('order_type', $orderType)
                ->delete();

            foreach ($resolved as $idx => $line) {
                $row = OrderPackagingLine::withTrashed()
                    ->where('company_id', $companyId)
                    ->where('order_type', $orderType)
                    ->where($line['ingredient_id'] !== null ? 'ingredient_id' : 'product_id', $line['ingredient_id'] ?? $line['product_id'])
                    ->orderByDesc('id')
                    ->first();
                $values = [
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'entered_unit' => $line['entered_unit'],
                    'entered_quantity' => $line['entered_quantity'],
                    'sort_order' => $idx,
                ];
                if ($row !== null) {
                    $row->fill($values);
                    $row->deleted_at = null;
                    $row->save();
                } else {
                    OrderPackagingLine::query()->create($values + [
                        'company_id' => $companyId,
                        'order_type' => $orderType,
                        'ingredient_id' => $line['ingredient_id'],
                        'product_id' => $line['product_id'],
                    ]);
                }
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'inventory.order_packaging_updated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: Company::class,
                auditableId: $companyId,
                oldValues: ['order_type' => $orderType, 'lines' => array_column($before, 'readable')],
                newValues: ['order_type' => $orderType, 'lines' => array_column($after, 'readable')],
            ));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{ingredient_id: ?int, product_id: ?int, quantity: string, unit: ?string, entered_unit: ?string, entered_quantity: ?string, name: string}>
     */
    private function resolve(array $lines, int $companyId): array
    {
        $ingredientUuids = [];
        $productUuids = [];
        foreach ($lines as $line) {
            if (($line['type'] ?? '') === 'ingredient') {
                $ingredientUuids[] = (string) ($line['ingredient_uuid'] ?? '');
            } else {
                $productUuids[] = (string) ($line['product_uuid'] ?? '');
            }
        }
        $ingredients = Ingredient::query()->where('company_id', $companyId)->whereIn('uuid', array_filter($ingredientUuids))->with('altUnits')->get()->keyBy('uuid');
        $products = Product::query()->where('company_id', $companyId)->whereIn('uuid', array_filter($productUuids))->get()->keyBy('uuid');

        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            if ((float) ($line['quantity'] ?? 0) <= 0) {
                throw new RuntimeException('Packaging amounts must be more than 0.');
            }
            if (($line['type'] ?? '') === 'ingredient') {
                /** @var Ingredient|null $ingredient */
                $ingredient = $ingredients[(string) ($line['ingredient_uuid'] ?? '')] ?? null;
                if ($ingredient === null) {
                    throw new RuntimeException('One or more packaging items do not belong to your company.');
                }
                if ($ingredient->isPrep()) {
                    throw new RuntimeException(sprintf('"%s" is a prep item — packaging is never cooked: pick an ingredient or a physical item.', $ingredient->name));
                }
                $amount = $this->quantities->resolve($ingredient, $line['quantity'], $line['unit'] ?? null);
                $key = 'i:'.$ingredient->id;
                $entry = [
                    'ingredient_id' => (int) $ingredient->id,
                    'product_id' => null,
                    'quantity' => $amount['quantity'],
                    'unit' => $ingredient->unit?->value,
                    'entered_unit' => $amount['entered_unit'],
                    'entered_quantity' => $amount['entered_quantity'],
                    'name' => (string) $ingredient->name,
                ];
            } else {
                /** @var Product|null $product */
                $product = $products[(string) ($line['product_uuid'] ?? '')] ?? null;
                if ($product === null) {
                    throw new RuntimeException('One or more packaging items do not belong to your company.');
                }
                if ($product->stock_mode !== 'unit' || $product->isCombo()) {
                    throw new RuntimeException(sprintf('"%s" is not counted in pieces — packaging is a physical item or a bought-in product.', $product->name));
                }
                if ($product->internal_purpose === 'general') {
                    throw new RuntimeException(sprintf('"%s" is a branch-use physical item — it is never packed with an order.', $product->name));
                }
                [$pieces, $enteredUnit, $enteredQuantity] = $this->pieces($product, (string) $line['quantity'], $line['unit'] ?? null);
                $key = 'p:'.$product->id;
                $entry = [
                    'ingredient_id' => null,
                    'product_id' => (int) $product->id,
                    'quantity' => $pieces,
                    'unit' => null,
                    'entered_unit' => $enteredUnit,
                    'entered_quantity' => $enteredQuantity,
                    'name' => (string) $product->name,
                ];
            }
            if (isset($seen[$key])) {
                throw new RuntimeException(sprintf('"%s" is on the list twice — put the whole amount on one line.', $entry['name']));
            }
            $seen[$key] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Pieces of a physical item: typed in pieces (3 decimals), or N of one of
     * its packs (its token) = N × the pack's pieces.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function pieces(Product $product, string $quantity, ?string $unit): array
    {
        $unit = $unit === null ? '' : trim($unit);
        if ($unit === '' || $unit === 'piece') {
            if (round((float) $quantity, 3) != (float) $quantity) {
                throw new RuntimeException(sprintf('"%s": pieces keep at most 3 decimal places.', $product->name));
            }

            return [number_format((float) $quantity, 3, '.', ''), null, null];
        }

        $uuid = ContainerToken::decode($unit);
        $pack = $uuid === null ? null : ProductPack::query()
            ->where('company_id', $product->company_id)
            ->where('product_id', $product->id)
            ->where('uuid', $uuid)
            ->first();
        if ($pack === null) {
            throw new RuntimeException(sprintf('"%s": that pack does not belong to this item.', $product->name));
        }
        $count = BigDecimal::of(trim($quantity));
        if (! $count->isEqualTo($count->toScale(0, RoundingMode::DOWN))) {
            throw new RuntimeException(sprintf('"%s": count whole packs.', $product->name));
        }
        $pieces = $count->multipliedBy((string) $pack->pieces)->toScale(3, RoundingMode::HALF_UP);

        return [(string) $pieces, ContainerToken::encode((string) $pack->uuid), (string) StockDecimal::format((string) $count, 0, 4)];
    }

    /**
     * One line as compared and audited: "Napkin: 3 piece" / "Sugar: 10 g".
     *
     * @return array{key: string, readable: string}
     */
    private function shape(?int $ingredientId, ?int $productId, string $quantity, ?string $enteredUnit, string|int|float|null $enteredQuantity, string $name, ?string $unit): array
    {
        $qty = RecipeQuantity::trim(BigDecimal::of($quantity === '' ? '0' : $quantity));
        $ref = $ingredientId !== null ? 'i:'.$ingredientId : 'p:'.$productId;
        $entered = ($enteredUnit !== null && $enteredUnit !== '') ? $enteredUnit.'='.RecipeQuantity::trim(BigDecimal::of((string) $enteredQuantity)) : '';

        return [
            'key' => $ref.'|'.$qty.'|'.$entered,
            'readable' => $name.': '.$qty.' '.($ingredientId !== null ? (string) $unit : 'piece'),
        ];
    }
}
