<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Loyalty\AdjustPointBalanceAction;
use App\Actions\Pos\Loyalty\AdjustWalletBalanceAction;
use App\Actions\Pos\Loyalty\TopUpWalletAction;
use App\Actions\Pos\Loyalty\UpsertLoyaltyConfigAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Loyalty\AdjustPointBalanceRequest;
use App\Http\Requests\Pos\Loyalty\AdjustWalletBalanceRequest;
use App\Http\Requests\Pos\Loyalty\TopUpWalletRequest;
use App\Http\Requests\Pos\Loyalty\UpsertLoyaltyConfigRequest;
use App\Http\Resources\Pos\Loyalty\LoyaltyConfigResource;
use App\Http\Resources\Pos\Loyalty\PointLedgerEntryResource;
use App\Http\Resources\Pos\Loyalty\WalletLedgerEntryResource;
use App\Models\Customer;
use App\Models\CustomerLoyaltyConfig;
use App\Models\CustomerPointLedgerEntry;
use App\Models\CustomerWalletLedgerEntry;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

/**
 * Phase 6b — loyalty config + per-customer adjustments + ledger
 * history.
 *
 *   GET    /api/loyalty/config                              → show config (creates default if absent)
 *   PATCH  /api/loyalty/config                              → upsert config
 *
 *   GET    /api/customers/{customer:uuid}/loyalty           → balances + config snapshot
 *   POST   /api/customers/{customer:uuid}/points/adjust     → manual point adjustment
 *   POST   /api/customers/{customer:uuid}/wallet/topup      → manual wallet top-up
 *   POST   /api/customers/{customer:uuid}/wallet/adjust     → manual wallet adjustment
 *
 *   GET    /api/customers/{customer:uuid}/points/ledger     → paginated history
 *   GET    /api/customers/{customer:uuid}/wallet/ledger     → paginated history
 *
 * Permission gating:
 *   - LoyaltyView   for GET endpoints
 *   - LoyaltyManage for every write
 *
 * All endpoints tenant-scoped. Customer model binding +
 * refuseIfNotInTenant defend against cross-merchant access.
 */
class LoyaltyController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly UpsertLoyaltyConfigAction $upsertConfig,
        private readonly AdjustPointBalanceAction $adjustPoints,
        private readonly AdjustWalletBalanceAction $adjustWallet,
        private readonly TopUpWalletAction $topUpWallet,
    ) {}

    // =================== CONFIG ===================

    public function showConfig(Request $request): LoyaltyConfigResource
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $config = $this->resolveOrCreateConfig();
        return LoyaltyConfigResource::make($config);
    }

    public function upsertConfig(UpsertLoyaltyConfigRequest $request): LoyaltyConfigResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        try {
            $config = $this->upsertConfig->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return LoyaltyConfigResource::make($config);
    }

    // =================== PER-CUSTOMER SUMMARY ===================

    public function showCustomer(Request $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        // Compact summary payload — balances + config + the
        // most recent N entries per ledger so the customer-
        // modal Loyalty section can render without a second
        // round-trip. Full pagination is on the /ledger
        // endpoints below.
        $config = $this->resolveOrCreateConfig();
        $recentPoints = $customer->pointLedger()->take(5)->get();
        $recentWallet = $customer->walletLedger()->take(5)->get();

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'uuid' => $customer->uuid,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'points_balance' => (int) $customer->points_balance,
                    'wallet_balance' => (string) $customer->wallet_balance,
                ],
                'config' => (new LoyaltyConfigResource($config))->resolve($request),
                'recent_points' => PointLedgerEntryResource::collection($recentPoints)->resolve($request),
                'recent_wallet' => WalletLedgerEntryResource::collection($recentWallet)->resolve($request),
            ],
        ]);
    }

    // =================== POINT ADJUST ===================

    public function adjustPoints(AdjustPointBalanceRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $entry = $this->adjustPoints->handle(
                $customer,
                (int) $request->validated()['points_delta'],
                $request->user(),
                (string) $request->validated()['reason'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Refresh the customer to pick up the new running total.
        $customer->refresh();

        return response()->json([
            'data' => [
                'entry' => (new PointLedgerEntryResource($entry))->resolve($request),
                'points_balance' => (int) $customer->points_balance,
            ],
        ], 201);
    }

    // =================== WALLET TOP-UP ===================

    public function topUpWallet(TopUpWalletRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $entry = $this->topUpWallet->handle(
                $customer,
                (string) $request->validated()['amount'],
                $request->user(),
                $request->validated()['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $customer->refresh();

        return response()->json([
            'data' => [
                'entry' => (new WalletLedgerEntryResource($entry))->resolve($request),
                'wallet_balance' => (string) $customer->wallet_balance,
            ],
        ], 201);
    }

    // =================== WALLET ADJUST ===================

    public function adjustWallet(AdjustWalletBalanceRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $entry = $this->adjustWallet->handle(
                $customer,
                (string) $request->validated()['amount_delta'],
                $request->user(),
                (string) $request->validated()['reason'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $customer->refresh();

        return response()->json([
            'data' => [
                'entry' => (new WalletLedgerEntryResource($entry))->resolve($request),
                'wallet_balance' => (string) $customer->wallet_balance,
            ],
        ], 201);
    }

    // =================== LEDGERS ===================

    public function pointLedger(Request $request, Customer $customer): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        $perPage = min((int) $request->query('per_page', 50), 200);

        return CustomerPointLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (CustomerPointLedgerEntry $e): array => (new PointLedgerEntryResource($e))->resolve($request));
    }

    public function walletLedger(Request $request, Customer $customer): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        $perPage = min((int) $request->query('per_page', 50), 200);

        return CustomerWalletLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (CustomerWalletLedgerEntry $e): array => (new WalletLedgerEntryResource($e))->resolve($request));
    }

    // =================== HELPERS ===================

    /**
     * Lazy-create the per-company config on first read so the
     * customer-loyalty summary endpoint never returns a null
     * config. Uses firstOrCreate with the production defaults.
     *
     * Returns a fresh()'d instance so the JsonResource layer
     * doesn't see wasRecentlyCreated=true and respond with a
     * 201 on what is semantically a GET — the lazy-init is an
     * implementation detail, the user sees a normal 200.
     */
    private function resolveOrCreateConfig(): CustomerLoyaltyConfig
    {
        $companyId = $this->tenant->requiredId();
        $config = CustomerLoyaltyConfig::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'points_per_omr' => 0,
                'baisas_per_point' => 10,
                'is_active' => false,
            ],
        );
        return $config->fresh();
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(Customer $customer): void
    {
        if ((int) $customer->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
