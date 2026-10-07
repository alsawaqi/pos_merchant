<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Inventory\CreatePurchaseReceiptAction;
use App\Actions\Pos\Inventory\ResolvePurchaseContainerLineAction;
use App\Actions\Pos\Inventory\ResolvePurchaseLineUnitAction;
use App\Actions\Pos\Inventory\WriteReceiptPaymentAction;
use App\Enums\ExpenseCategory;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Inventory\RecordReceiptPaymentRequest;
use App\Http\Requests\Pos\Inventory\StorePurchaseReceiptRequest;
use App\Http\Resources\Pos\Inventory\PurchaseReceiptResource;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Support\BranchScope;
use App\Support\Costs\PriceAlerts;
use App\Support\Costs\PriceHistory;
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
        private readonly WriteReceiptPaymentAction $writePayment,
        private readonly ResolvePurchaseLineUnitAction $lineUnits,
        private readonly ResolvePurchaseContainerLineAction $containerLines,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::InventoryView);

        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = PurchaseReceipt::query()
            ->where('company_id', $this->tenant->requiredId())
            ->with(['supplier', 'destinationBranch'])
            ->withCount('lines');

        // AP — filter the payables: ?payment_status=outstanding shows everything
        // not fully paid (the unpaid-receipts list), or a specific status.
        $status = (string) $request->query('payment_status', '');
        if ($status === 'outstanding') {
            $query->where('payment_status', '!=', 'paid');
        } elseif (in_array($status, ['paid', 'partial', 'unpaid'], true)) {
            $query->where('payment_status', $status);
        }

        $receipts = $query
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

        // Phase B — direct-to-branch delivery: the supplier dropped the goods
        // at a branch, not the central warehouse. Tenant-scoped resolve; the
        // action auto-allocates every line in full to it.
        $destination = null;
        if ($request->filled('destination_branch_uuid')) {
            $destination = Branch::query()
                ->where('company_id', $companyId)
                ->where('uuid', $request->input('destination_branch_uuid'))
                ->first();
            if ($destination === null) {
                return response()->json(['message' => 'Destination branch not found.'], 422);
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
        // AP — a credit buy defers the cash; a due date is an optional reminder.
        $isCredit = $request->boolean('is_credit');
        $dueDate = $request->filled('due_date')
            ? Carbon::parse((string) $request->input('due_date'))
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
                $isCredit,
                $dueDate,
                $destination,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PurchaseReceiptResource(
                $receipt->load(['lines', 'charges', 'supplier', 'recordedByUser', 'destinationBranch'])
            ))->resolve($request) + ['price_alerts' => $this->priceAlerts($request, $receipt)],
        ], 201);
    }

    public function show(Request $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryView);
        $this->refuseIfNotInTenant($receipt);

        $receipt->load(['lines', 'charges', 'supplier', 'recordedByUser', 'destinationBranch', 'payments.recordedByUser']);

        return response()->json([
            'data' => (new PurchaseReceiptResource($receipt))->resolve($request) + ['price_alerts' => $this->priceAlerts($request, $receipt)],
        ]);
    }

    /**
     * LAUNCH costs & allergens add-on (tester call 1) — the purchase
     * confirmation's price alerts: this receipt's ingredient lines whose
     * price per base unit moved by at least the threshold from the previous
     * purchase ({@see PriceAlerts}); the dishes affected only for a user who
     * may see costs (reports.view).
     *
     * @return list<array<string, mixed>>
     */
    private function priceAlerts(Request $request, PurchaseReceipt $receipt): array
    {
        $companyId = (int) $receipt->company_id;
        $lineIds = $receipt->lines->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $rows = PriceHistory::alerts($companyId, now(), lineIds: $lineIds);

        return PriceAlerts::present($companyId, $rows, withDishes: (bool) $request->user()?->can(MerchantPermission::ReportsView->value));
    }

    /**
     * AP — record a payment (full or partial) against a credit receipt and
     * return the refreshed document with its updated balance + history. A
     * payment settles the supplier, NOT the books: the cost was already
     * expensed at receive, so no new expense is booked here.
     */
    public function recordPayment(RecordReceiptPaymentRequest $request, PurchaseReceipt $receipt): JsonResponse
    {
        $this->ensure($request, MerchantPermission::InventoryManage);
        $this->refuseIfNotInTenant($receipt);

        $paidAt = $request->filled('paid_at')
            ? Carbon::parse((string) $request->input('paid_at'))
            : null;

        try {
            $this->writePayment->handle(
                $receipt,
                $request->input('amount'),
                $request->user(),
                $request->input('method'),
                $request->input('note'),
                $paidAt,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $receipt->refresh()->load(['lines', 'charges', 'supplier', 'recordedByUser', 'payments.recordedByUser']);

        return response()->json([
            'data' => (new PurchaseReceiptResource($receipt))->resolve($request),
        ]);
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
            // LAUNCH-P3 P3-4 — goods are never received into a prep item.
            if ($ingredient->isPrep()) {
                try {
                    $ingredient->ensureStocked();
                } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
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
            // A purchase receipt records BOUGHT goods, so only ready/bought-in
            // (unit) products — and physical items, which are unit-mode — may be
            // a line. Cooked + made-to-order ('ingredient') products are
            // recipe/kitchen-driven: their shelf stock is written by production,
            // never purchased; untracked products hold no stock. All are refused.
            // (This deliberately DIVERGES from ProductStockController::
            // requireUnitProduct, which also admits cooked — there for adjusting /
            // transferring / viewing an existing cooked shelf, not buying one.)
            if ($product->stock_mode !== 'unit') {
                return response()->json([
                    'message' => 'Only ready/bought-in (unit) products or physical items can be received on a purchase receipt. Cooked and made-to-order items are stocked from their recipe, not purchased.',
                ], 422);
            }
            $resolved['product'] = $product;
        }

        $byContainer = isset($row['pieces']) && $row['pieces'] !== null && $row['pieces'] !== '';

        $allocations = [];
        foreach ((array) ($row['allocations'] ?? []) as $alloc) {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('uuid', (string) ($alloc['branch_uuid'] ?? ''))
                ->first();
            if ($branch === null) {
                return response()->json(['message' => 'A selected branch was not found.'], 422);
            }
            $allocations[] = $byContainer
                ? ['branch' => $branch, 'pieces' => $alloc['pieces'] ?? $alloc['quantity'] ?? 0]
                : ['branch' => $branch, 'quantity' => $alloc['quantity']];
        }

        // LAUNCH review add-on (C1, D3) — bought BY CONTAINER (an ingredient's
        // container, a physical item's pack): pieces, an amount that may only
        // be lowered, the price paid for the line, and the split in pieces.
        if ($byContainer) {
            try {
                $resolvedLine = $this->containerLines->handle($resolved['ingredient'], $resolved['product'], $row, $allocations);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return $resolved + [
                'quantity' => $resolvedLine['quantity'],
                'line_cost' => $resolvedLine['line_cost'],
                'tax_amount' => $row['tax_amount'] ?? null,
                'tax_rate' => $row['tax_rate'] ?? null,
                'allocations' => $resolvedLine['allocations'],
                'purchase_unit' => null,
                'purchase_quantity' => null,
                'unit_price' => null,
                'paid_unit_cost' => null,
                'container' => $resolvedLine['container'],
                'pack' => $resolvedLine['pack'],
                'container_label' => $resolvedLine['container_label'],
                'container_factor' => $resolvedLine['container_factor'],
                'pieces' => $resolvedLine['pieces'],
                'leaves' => $resolvedLine['leaves'],
            ];
        }

        // LAUNCH-P2 P2-3 — the line may be entered in a purchase unit with a
        // price per that unit: convert quantity + split to base units and the
        // price to a per-base-unit cost (the stock side stores base units).
        try {
            $units = $this->lineUnits->handle($resolved['ingredient'], $row, $allocations);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $resolved['quantity'] = $units['quantity'];
        $resolved['line_cost'] = $units['line_cost'];
        $resolved['tax_amount'] = $row['tax_amount'] ?? null;
        $resolved['tax_rate'] = $row['tax_rate'] ?? null;
        $resolved['allocations'] = $units['allocations'];
        $resolved['purchase_unit'] = $units['purchase_unit'];
        $resolved['purchase_quantity'] = $units['purchase_quantity'];
        $resolved['unit_price'] = $units['unit_price'];
        $resolved['paid_unit_cost'] = $units['paid_unit_cost'];

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
