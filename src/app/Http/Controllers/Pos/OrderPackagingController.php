<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\SaveOrderPackagingAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\OrderPackagingLine;
use App\Models\Product;
use App\Models\ProductPack;
use App\Support\Inventory\PackagingUsage;
use App\Support\Inventory\Packs;
use App\Support\Catalogue\OrderTypes;
use App\Support\Inventory\ContainerToken;
use App\Support\MerchantTenantContext;
use App\Support\Recipes\RecipeEditGate;
use App\Support\Recipes\RecipeQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * LAUNCH packaging add-on — Inventory → "Order packaging".
 *
 *   GET /api/inventory/order-packaging               the four lists (Dine in,
 *        Quick order, To go, Delivery) with today's cost per order;
 *        catalogue.view OR inventory.view (tester call 5).
 *   PUT /api/inventory/order-packaging/{orderType}   replace one list;
 *        "Edit recipes" ({@see SaveOrderPackagingAction}).
 *
 * pos_api takes the list of the order's final type ONCE per whole order,
 * when it takes the order's stock, and freezes it on the order.
 */
final class OrderPackagingController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly RecipeQuantity $quantities,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureCanRead($request);

        return response()->json(['data' => $this->present($request)]);
    }

    public function update(Request $request, string $orderType, SaveOrderPackagingAction $action): JsonResponse
    {
        $this->ensureCanRead($request);
        if (! array_key_exists($orderType, OrderTypes::BUCKETS)) {
            abort(404);
        }
        if (! RecipeEditGate::allows($request->user())) {
            abort(403, RecipeEditGate::MESSAGE);
        }

        $validated = $request->validate([
            'lines' => ['present', 'array', 'max:'.SaveOrderPackagingAction::MAX_LINES],
            'lines.*.type' => ['required', 'string', 'in:ingredient,product'],
            'lines.*.ingredient_uuid' => ['required_if:lines.*.type,ingredient', 'nullable', 'string', 'uuid'],
            'lines.*.product_uuid' => ['required_if:lines.*.type,product', 'nullable', 'string', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:999999.999'],
            // An ingredient's unit (base, metric pair, container token) or a
            // physical item's pack token; empty = base / pieces.
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $action->handle($orderType, array_values($validated['lines']), $request->user());
        } catch (QueryException|HttpException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($request)]);
    }

    /** @return array<string, mixed> */
    private function present(Request $request): array
    {
        $companyId = $this->tenant->requiredId();
        $rows = OrderPackagingLine::query()
            ->where('company_id', $companyId)
            ->with(['ingredient.altUnits', 'product'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('order_type');

        $lists = [];
        foreach (array_keys(OrderTypes::BUCKETS) as $type) {
            $lines = [];
            $total = BigDecimal::zero();
            $complete = true;
            foreach ($rows->get($type, collect()) as $row) {
                $line = $this->line($row);
                if ($line['cost'] === null) {
                    $complete = false;
                } else {
                    $total = $total->plus($line['cost']);
                }
                $lines[] = $line;
            }
            $lists[$type] = [
                'lines' => $lines,
                'cost' => (string) $total->toScale(3, RoundingMode::HALF_UP),
                'cost_complete' => $complete,
            ];
        }

        return [
            'can_edit' => RecipeEditGate::allows($request->user()),
            'lists' => $lists,
            // Fix order PK-B1 (L4) — the item picker comes with the lists, so a
            // user who may edit them (catalogue.view + "Edit recipes", no
            // inventory.view) gets working pickers: physical items used with
            // food and bought-in products, active, with their packs.
            'items' => $this->items($companyId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(int $companyId): array
    {
        $products = Product::query()
            ->where('company_id', $companyId)
            ->where('stock_mode', 'unit')
            ->where('status', 'active')
            ->where(static fn ($q) => $q->whereNull('internal_purpose')->orWhere('internal_purpose', '!=', 'general'))
            ->where(static fn ($q) => $q->whereNull('product_type')->orWhere('product_type', '!=', 'combo'))
            ->orderBy('name')
            ->get();
        $packs = ProductPack::query()->whereIn('product_id', $products->pluck('id')->all() ?: [0])->orderBy('sort_order')->orderBy('id')->get()->groupBy('product_id');

        return $products->map(static function (Product $p) use ($packs): array {
            $all = $packs->get($p->id, collect());

            return [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'name_ar' => $p->name_ar,
                'kind' => $p->is_internal ? 'physical' : 'bought_in',
                'cost_price' => $p->cost_price !== null ? (string) $p->cost_price : null,
                'packs' => $all->map(static fn (ProductPack $pack): array => [
                    'uuid' => $pack->uuid,
                    'token' => ContainerToken::encode((string) $pack->uuid),
                    'pieces' => (string) $pack->pieces,
                    'display_name' => Packs::displayName($pack, $all, 'en'),
                    'display_name_ar' => Packs::displayName($pack, $all, 'ar'),
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function line(OrderPackagingLine $row): array
    {
        $quantity = (string) $row->quantity;
        if ($row->ingredient !== null) {
            $ingredient = $row->ingredient;
            $shown = $this->quantities->display($ingredient, $quantity, $row->entered_unit, $row->entered_quantity);
            $unitCost = BigDecimal::of((string) ($ingredient->default_unit_cost ?? '0'));

            return [
                'type' => 'ingredient',
                'ingredient_uuid' => $ingredient->uuid,
                'product_uuid' => null,
                'name' => $ingredient->name,
                'name_ar' => $ingredient->name_ar,
                'quantity' => $quantity,
                'unit' => $ingredient->unit?->value,
                'entered_unit' => $shown['entered'] ? $shown['unit'] : null,
                'entered_quantity' => $shown['entered'] ? $shown['quantity'] : null,
                'pack_uuid' => null,
                // Fix order PK-B1 (M2/M3) — false when pos_api would skip it.
                'available' => ! $ingredient->trashed() && self::active($ingredient->status),
                'cost' => $unitCost->isPositive() ? (string) BigDecimal::of($quantity)->multipliedBy($unitCost)->toScale(3, RoundingMode::HALF_UP) : null,
            ];
        }

        $product = $row->product;
        // Fix order PK-B1 (M1) — the pack form only while it adds up to the stored pieces.
        [$packUnit, $packCount] = PackagingUsage::packForm($row->product_id, $quantity, $row->entered_unit, $row->entered_quantity);
        $costPrice = BigDecimal::of((string) ($product?->cost_price ?? '0'));

        return [
            'type' => 'product',
            'ingredient_uuid' => null,
            'product_uuid' => $product?->uuid,
            'name' => $product?->name,
            'name_ar' => $product?->name_ar,
            'quantity' => $quantity,
            'unit' => null,
            'entered_unit' => $packUnit,
            'entered_quantity' => $packCount,
            'pack_uuid' => ContainerToken::decode($packUnit),
            'available' => $product !== null && ! $product->trashed() && self::active($product->status),
            'cost' => $costPrice->isPositive() ? (string) BigDecimal::of($quantity)->multipliedBy($costPrice)->toScale(3, RoundingMode::HALF_UP) : null,
        ];
    }

    private static function active(mixed $status): bool
    {
        return ($status instanceof \BackedEnum ? $status->value : (string) ($status ?? 'active')) === 'active';
    }

    private function ensureCanRead(Request $request): void
    {
        $user = $request->user();
        if ($user === null || ! ($user->can(MerchantPermission::CatalogueView->value) || $user->can(MerchantPermission::InventoryView->value))) {
            abort(403);
        }
    }
}
