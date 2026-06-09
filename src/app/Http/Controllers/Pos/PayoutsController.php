<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Support\MerchantTenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * v2 #17 (Phase B) — the merchant's own payout history (read-only).
 *
 *   GET /api/payouts[?status=]   → this company's payouts, newest first.
 *
 * Payouts are created + settled by the platform (pos_admin); the merchant just
 * sees what they've been / will be paid. reports.view gated, tenant-scoped.
 */
class PayoutsController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ReportsView->value)) {
            abort(403);
        }

        $query = Payout::query()
            ->where('company_id', $this->tenant->requiredId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $payouts = $query->orderByDesc('created_at')->paginate(50);

        return JsonResource::collection($payouts->through(static fn (Payout $p): array => [
            'uuid' => $p->uuid,
            'period_from' => $p->period_from?->toIso8601String(),
            'period_to' => $p->period_to?->toIso8601String(),
            'status' => $p->status,
            'gross_amount' => (string) $p->gross_amount,
            'platform_amount' => (string) $p->platform_amount,
            'bank_amount' => (string) $p->bank_amount,
            'other_amount' => (string) $p->other_amount,
            'net_amount' => (string) $p->net_amount,
            'sales_count' => (int) $p->sales_count,
            'reference' => $p->reference,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ]));
    }
}
