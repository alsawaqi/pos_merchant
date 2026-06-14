<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\CreatePurchaseReceiptAction;
use App\Enums\ExpenseCategory;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\StorePurchaseReceiptRequest;
use App\Http\Resources\Pos\Inventory\PurchaseReceiptResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * PD6 — the Goods Received Note (Saved Purchase Receipt).
 *
 *   GET  /api/purchase-receipts                 → paginated list (header + supplier + lines_count)
 *   POST /api/purchase-receipts                 → record a whole delivery in one submit
 *   GET  /api/purchase-receipts/{uuid}          → the full saved document (lines + charges)
 *
 * A receipt arrives at the company's central warehouse, so creating one is an
 * HQ act (matches the per-item receives): inventory.manage + unrestricted branch
 * scope. The heavy lifting is delegated to {@see CreatePurchaseReceiptAction},
 * which composes the existing receive/allocate/expense machinery.
 */
class PurchaseReceiptController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreatePurchaseReceiptAction $create,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $perPage = min((int) $request->query('per_page', 20), 100);

        $receipts = PurchaseReceipt::query()
            ->where('company_id', $this->tenant->requiredId())
            ->with('supplier')
            ->withCount('lines')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return PurchaseReceiptResource::collection($receipts);
    }

    public function store(StorePurchaseReceiptRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        // P-G5 — a receipt credits the central warehouse, an HQ resource.
        BranchScope::ensureUnrestricted(
            $request->user(),
            'Purchase receipts are recorded by accounts with access to all branches.',
        );

        $companyId = $this->tenant->requiredId();

        $supplier = null;
        if ($request->filled('supplier_uuid')) {
            $supplier = Supplier::query()
                ->where('company_id', $companyId)
                ->where('uuid', $request->input('supplier_uuid'))
                ->first();
            if ($supplier === null) {
                return response()->json(['message' => 'Supplier not found.'], 422);
            }
        }

        $lines = [];
        foreach ((array) $request->input('lines', []) as $row) {
            $resolved = $this->resolveLine($companyId, (array) $row);
            if ($resolved instanceof JsonResponse) {
                return $resolved;
            }
            $lines[] = $resolved;
        }

        $charges = [];
        foreach ((array) $request->input('charges', []) as $row) {
            $charges[] = [
                'name' => (string) $row['name'],
                'category' => ExpenseCategory::from((string) $row['category']),
                'amount' => $row['amount'],
                'tax_amount' => $row['tax_amount'] ?? null,
                'tax_rate' => $row['tax_rate'] ?? null,
            ];
        }

        $receivedAt = $request->filled('received_at')
            ? Carbon::parse((string) $request->input('received_at'))
            : null;

        try {
            $receipt = $this->create->handle(
                $companyId,
                $supplier,
                $request->input('reference'),
                $receivedAt,
                $request->input('note'),
                $lines,
                $charges,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PurchaseReceiptResource(
                $receipt->load(['lines', 'charges', 'supplier', 'recordedByUser'])
            ))->resolve($request),
        ], 201);
    }

    public function show(Request $request, PurchaseReceipt $receipt): PurchaseReceiptResource
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($receipt);

        $receipt->load(['lines', 'charges', 'supplier', 'recordedByUser']);

        return PurchaseReceiptResource::make($receipt);
    }

    // ---- helpers ----------------------------------------------

    /**
     * Resolve a request line into the action's typed shape, or return a 422
     * JsonResponse on a bad item/branch reference.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveLine(int $companyId, array $row): array|JsonResponse
    {
        $type = (string) $row['item_type'];
        $uuid = (string) $row['item_uuid'];

        $resolved = ['item_type' => $type, 'ingredient' => null, 'product' => null];

        if ($type === 'ingredient') {
            $ingredient = Ingredient::query()
                ->where('company_id', $companyId)
                ->where('uuid', $uuid)
                ->first();
            if ($ingredient === null) {
                return response()->json(['message' => 'An ingredient on the receipt was not found.'], 422);
            }
            $resolved['ingredient'] = $ingredient;
        } else {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('uuid', $uuid)
                ->first();
            if ($product === null) {
                return response()->json(['message' => 'A product on the receipt was not found.'], 422);
            }
            // Mirrors ProductStockController::requireUnitProduct — only
            // unit/cooked products (and physical items) hold unit stock.
            if (! in_array($product->stock_mode, ['unit', 'cooked'], true)) {
                return response()->json([
                    'message' => 'Only finished-good (unit), cooked products, or physical items can be received here.',
                ], 422);
            }
            $resolved['product'] = $product;
        }

        $allocations = [];
        foreach ((array) ($row['allocations'] ?? []) as $alloc) {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('uuid', (string) ($alloc['branch_uuid'] ?? ''))
                ->first();
            if ($branch === null) {
                return response()->json(['message' => 'A selected branch was not found.'], 422);
            }
            $allocations[] = ['branch' => $branch, 'quantity' => $alloc['quantity']];
        }

        $resolved['quantity'] = $row['quantity'];
        $resolved['line_cost'] = $row['line_cost'];
        $resolved['tax_amount'] = $row['tax_amount'] ?? null;
        $resolved['tax_rate'] = $row['tax_rate'] ?? null;
        $resolved['allocations'] = $allocations;

        return $resolved;
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(PurchaseReceipt $receipt): void
    {
        if ((int) $receipt->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
