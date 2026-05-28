<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Loyalty\AdjustLoyaltyAction;
use App\Actions\Pos\Loyalty\AdjustWalletBalanceAction;
use App\Actions\Pos\Loyalty\CreateLoyaltyRuleAction;
use App\Actions\Pos\Loyalty\DeleteLoyaltyRuleAction;
use App\Actions\Pos\Loyalty\EnsureLoyaltyAccountAction;
use App\Actions\Pos\Loyalty\PauseLoyaltyRuleAction;
use App\Actions\Pos\Loyalty\ResumeLoyaltyRuleAction;
use App\Actions\Pos\Loyalty\TopUpWalletAction;
use App\Actions\Pos\Loyalty\UpdateLoyaltyRuleAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Loyalty\AdjustLoyaltyRequest;
use App\Http\Requests\Pos\Loyalty\AdjustWalletBalanceRequest;
use App\Http\Requests\Pos\Loyalty\CreateLoyaltyRuleRequest;
use App\Http\Requests\Pos\Loyalty\TopUpWalletRequest;
use App\Http\Requests\Pos\Loyalty\UpdateLoyaltyRuleRequest;
use App\Http\Resources\Pos\Loyalty\LoyaltyAccountResource;
use App\Http\Resources\Pos\Loyalty\LoyaltyRuleResource;
use App\Http\Resources\Pos\Loyalty\LoyaltyTransactionResource;
use App\Http\Resources\Pos\Loyalty\WalletLedgerEntryResource;
use App\Models\Customer;
use App\Models\CustomerWalletLedgerEntry;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

/**
 * Loyalty refactor — rules CRUD + per-customer accounts +
 * transactions, plus the (unchanged) wallet store-credit path.
 *
 * RULES (blueprint §5.8):
 *   GET    /api/loyalty/rules                                 list
 *   POST   /api/loyalty/rules                                 create
 *   PATCH  /api/loyalty/rules/{rule:uuid}                     update
 *   DELETE /api/loyalty/rules/{rule:uuid}                     soft delete
 *   POST   /api/loyalty/rules/{rule:uuid}/pause               active → paused
 *   POST   /api/loyalty/rules/{rule:uuid}/resume              paused → active
 *
 * CUSTOMER:
 *   GET  /api/customers/{customer:uuid}/loyalty               accounts + recent txns
 *   POST /api/customers/{customer:uuid}/loyalty/adjust        manual points/stamps adjust
 *   GET  /api/customers/{customer:uuid}/loyalty/transactions  paginated history
 *
 * WALLET (store credit — separate from blueprint loyalty):
 *   POST /api/customers/{customer:uuid}/wallet/topup
 *   POST /api/customers/{customer:uuid}/wallet/adjust
 *   GET  /api/customers/{customer:uuid}/wallet/ledger
 *
 * Permission gating: LoyaltyView for GETs, LoyaltyManage for writes.
 * All endpoints tenant-scoped.
 */
class LoyaltyController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly CreateLoyaltyRuleAction $createRule,
        private readonly UpdateLoyaltyRuleAction $updateRuleAction,
        private readonly PauseLoyaltyRuleAction $pauseRuleAction,
        private readonly ResumeLoyaltyRuleAction $resumeRuleAction,
        private readonly DeleteLoyaltyRuleAction $deleteRuleAction,
        private readonly EnsureLoyaltyAccountAction $ensureAccount,
        private readonly AdjustLoyaltyAction $adjustLoyalty,
        private readonly TopUpWalletAction $topUpWalletAction,
        private readonly AdjustWalletBalanceAction $adjustWalletAction,
    ) {}

    // =================== RULES ===================

    public function indexRules(Request $request): AnonymousResourceCollection
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);

        $rules = LoyaltyRule::query()
            ->where('company_id', $this->tenant->requiredId())
            ->withCount('accounts')
            ->orderBy('name')
            ->get();

        return LoyaltyRuleResource::collection($rules);
    }

    public function storeRule(CreateLoyaltyRuleRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);

        try {
            $rule = $this->createRule->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new LoyaltyRuleResource($rule))->resolve($request),
        ], 201);
    }

    public function updateRule(UpdateLoyaltyRuleRequest $request, LoyaltyRule $rule): LoyaltyRuleResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseRuleNotInTenant($rule);

        try {
            $updated = $this->updateRuleAction->handle($rule, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return LoyaltyRuleResource::make($updated);
    }

    public function destroyRule(Request $request, LoyaltyRule $rule): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseRuleNotInTenant($rule);

        $this->deleteRuleAction->handle($rule, $request->user());

        return response()->json(['data' => null], 204);
    }

    public function pauseRule(Request $request, LoyaltyRule $rule): LoyaltyRuleResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseRuleNotInTenant($rule);

        try {
            $updated = $this->pauseRuleAction->handle($rule, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return LoyaltyRuleResource::make($updated);
    }

    public function resumeRule(Request $request, LoyaltyRule $rule): LoyaltyRuleResource | JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseRuleNotInTenant($rule);

        try {
            $updated = $this->resumeRuleAction->handle($rule, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return LoyaltyRuleResource::make($updated);
    }

    // =================== PER-CUSTOMER ===================

    public function showCustomer(Request $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        $accounts = $customer->loyaltyAccounts()->with('rule')->get();
        $accountIds = $accounts->pluck('id')->all();

        $recentTransactions = LoyaltyTransaction::query()
            ->whereIn('loyalty_account_id', $accountIds === [] ? [0] : $accountIds)
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->take(10)
            ->get();

        $recentWallet = $customer->walletLedger()->take(5)->get();

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'uuid' => $customer->uuid,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'wallet_balance' => (string) $customer->wallet_balance,
                ],
                'accounts' => LoyaltyAccountResource::collection($accounts)->resolve($request),
                'recent_transactions' => LoyaltyTransactionResource::collection($recentTransactions)->resolve($request),
                'recent_wallet' => WalletLedgerEntryResource::collection($recentWallet)->resolve($request),
            ],
        ]);
    }

    public function adjust(AdjustLoyaltyRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        $validated = $request->validated();
        $rule = LoyaltyRule::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('uuid', (string) $validated['loyalty_rule_uuid'])
            ->first();
        if ($rule === null) {
            return response()->json(['message' => 'Loyalty rule not found.'], 422);
        }

        try {
            $account = $this->ensureAccount->handle($customer, $rule);
            $txn = $this->adjustLoyalty->handle(
                $account,
                (int) ($validated['points_delta'] ?? 0),
                (int) ($validated['stamps_delta'] ?? 0),
                $request->user(),
                (string) $validated['reason'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account->refresh();

        return response()->json([
            'data' => [
                'transaction' => (new LoyaltyTransactionResource($txn))->resolve($request),
                'account' => (new LoyaltyAccountResource($account->load('rule')))->resolve($request),
            ],
        ], 201);
    }

    public function transactions(Request $request, Customer $customer): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        $perPage = min((int) $request->query('per_page', '50'), 200);
        $accountIds = $customer->loyaltyAccounts()->pluck('id')->all();

        return LoyaltyTransaction::query()
            ->whereIn('loyalty_account_id', $accountIds === [] ? [0] : $accountIds)
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (LoyaltyTransaction $t): array => (new LoyaltyTransactionResource($t))->resolve($request));
    }

    // =================== WALLET (unchanged) ===================

    public function topUpWallet(TopUpWalletRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $entry = $this->topUpWalletAction->handle(
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

    public function adjustWallet(AdjustWalletBalanceRequest $request, Customer $customer): JsonResponse
    {
        $this->ensure($request, MerchantPermission::LoyaltyManage);
        $this->refuseIfNotInTenant($customer);

        try {
            $entry = $this->adjustWalletAction->handle(
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

    public function walletLedger(Request $request, Customer $customer): LengthAwarePaginator
    {
        $this->ensure($request, MerchantPermission::LoyaltyView);
        $this->refuseIfNotInTenant($customer);

        $perPage = min((int) $request->query('per_page', '50'), 200);

        return CustomerWalletLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->with('recordedBy')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (CustomerWalletLedgerEntry $e): array => (new WalletLedgerEntryResource($e))->resolve($request));
    }

    // =================== HELPERS ===================

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

    private function refuseRuleNotInTenant(LoyaltyRule $rule): void
    {
        if ((int) $rule->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
