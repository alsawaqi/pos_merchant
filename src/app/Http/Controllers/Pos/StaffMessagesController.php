<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Messaging\RetractStaffMessageAction;
use App\Actions\Pos\Messaging\SendStaffMessageAction;
use App\Enums\MerchantPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Messaging\SendStaffMessageRequest;
use App\Models\StaffMessage;
use App\Models\StaffMessageRead;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * P-G6 — staff announcements (portal → POS devices), channel 1.
 *
 *   GET    /api/staff-messages           → sent list + read receipts
 *   POST   /api/staff-messages           → compose (staff|branch|company)
 *   DELETE /api/staff-messages/{uuid}    → retract (devices purge)
 *
 * All gated on messages.send (a management surface: who saw what).
 * Branch-restricted senders (F5) reach only their branches' audiences.
 */
class StaffMessagesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SendStaffMessageAction $send,
        private readonly RetractStaffMessageAction $retract,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::MessagesSend);

        // P-G5 — a scoped sender's list shrinks to their branches'
        // announcements (company-wide ones are HQ sends they can't make
        // or audit).
        $allowed = $request->user()?->allowedBranchIds();

        $perPage = min((int) $request->query('per_page', 25), 100);

        $page = StaffMessage::query()
            ->where('company_id', $this->tenant->requiredId())
            ->when($allowed !== null, function ($q) use ($allowed): void {
                $q->where(function ($w) use ($allowed): void {
                    $w->whereIn('target_branch_id', $allowed)
                        ->orWhereHas('targetStaff', fn ($s) => $s->whereIn('branch_id', $allowed));
                });
            })
            ->with(['targetBranch:id,uuid,name', 'targetStaff:id,uuid,name', 'reads.staff:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (StaffMessage $m): array => $this->map($m));

        return response()->json($page);
    }

    public function store(SendStaffMessageRequest $request): JsonResponse
    {
        $this->ensure($request, MerchantPermission::MessagesSend);

        try {
            $message = $this->send->handle($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            // abort(403) inside the action (F5 scope) is an HttpException,
            // which extends RuntimeException — let it through as a 403.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message->load(['targetBranch:id,uuid,name', 'targetStaff:id,uuid,name', 'reads.staff:id,name']);

        return response()->json(['data' => $this->map($message)], 201);
    }

    public function destroy(Request $request, StaffMessage $staffMessage): JsonResponse
    {
        $this->ensure($request, MerchantPermission::MessagesSend);
        $this->refuseIfNotInTenant($staffMessage);

        // P-G5 — a scoped sender retracts only their branches' messages;
        // company-wide announcements are HQ artifacts (null → 403 for a
        // restricted user inside ensureBranch).
        if ($request->user()?->allowedBranchIds() !== null) {
            $branchId = match ($staffMessage->target_type) {
                StaffMessage::TARGET_BRANCH => (int) $staffMessage->target_branch_id,
                StaffMessage::TARGET_STAFF => $staffMessage->targetStaff?->branch_id !== null
                    ? (int) $staffMessage->targetStaff->branch_id
                    : null,
                default => null,
            };
            \App\Support\BranchScope::ensureBranch($request->user(), $branchId);
        }

        $this->retract->handle($staffMessage, $request->user());

        return response()->json(['data' => null], 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(StaffMessage $m): array
    {
        return [
            'uuid' => $m->uuid,
            'target_type' => $m->target_type,
            'target_branch' => $m->targetBranch !== null
                ? ['uuid' => $m->targetBranch->uuid, 'name' => $m->targetBranch->name]
                : null,
            'target_staff' => $m->targetStaff !== null
                ? ['uuid' => $m->targetStaff->uuid, 'name' => $m->targetStaff->name]
                : null,
            'title' => $m->title,
            'body' => $m->body,
            'created_by_name' => $m->created_by_name,
            'created_at' => $m->created_at?->toIso8601String(),
            // "Sent is not the same as seen" — who actually read it.
            'reads' => $m->reads
                ->map(fn (StaffMessageRead $r): array => [
                    'staff_name' => $r->staff?->name,
                    'read_at' => $r->read_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function ensure(Request $request, MerchantPermission $permission): void
    {
        $user = $request->user();
        if ($user === null || ! $user->can($permission->value)) {
            abort(403);
        }
    }

    private function refuseIfNotInTenant(StaffMessage $message): void
    {
        if ((int) $message->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
