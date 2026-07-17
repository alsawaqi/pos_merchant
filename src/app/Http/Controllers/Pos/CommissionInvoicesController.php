<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Reports\CommissionInvoiceBranchLinesAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Models\CommissionInvoice;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase B — the merchant's own commission invoices (read-only). What they OWE the
 * platform on cash/bank_pos sales; the reverse of the payout history.
 *
 *   GET /api/commission-invoices[?status=]          → this company's invoices, newest first.
 *   GET /api/commission-invoices/{invoice:uuid}/lines → the per-branch statement.
 *
 * Invoices are issued + collected by the platform (pos_admin); the merchant just
 * sees what they owe. reports.view gated, tenant-scoped.
 */
class CommissionInvoicesController extends Controller
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

        $query = CommissionInvoice::query()
            ->where('company_id', $this->tenant->requiredId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $invoices = $query->orderByDesc('created_at')->paginate(50);

        return JsonResource::collection($invoices->through(static fn (CommissionInvoice $i): array => [
            'uuid' => $i->uuid,
            'period_from' => $i->period_from?->toIso8601String(),
            'period_to' => $i->period_to?->toIso8601String(),
            'status' => $i->status,
            'gross_amount' => (string) $i->gross_amount,
            'cash_gross' => (string) $i->cash_gross,
            'bank_pos_gross' => (string) $i->bank_pos_gross,
            'platform_amount' => (string) $i->platform_amount,
            'other_amount' => (string) $i->other_amount,
            'merchant_amount' => (string) $i->merchant_amount,
            'total_owed' => (string) $i->total_owed,
            'sales_count' => (int) $i->sales_count,
            'reference' => $i->reference,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ]));
    }

    /** This invoice's per-branch breakdown (the statement detail). Own company only. */
    public function lines(Request $request, CommissionInvoice $invoice, CommissionInvoiceBranchLinesAction $branchLines): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can(MerchantPermission::ReportsView->value)) {
            abort(403);
        }
        // Tenant guard — a merchant may only see their own invoice.
        if ((int) $invoice->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }

        return response()->json(['data' => $branchLines->handle($invoice)]);
    }
}
