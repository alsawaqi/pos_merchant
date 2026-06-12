<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\Messaging\SendPortalMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Messaging\SendPortalMessageRequest;
use App\Models\PortalMessage;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * P-G6 — the portal → portal inbox (channel 2).
 *
 *   GET  /api/messages/inbox          → messages visible to ME (+ read flag)
 *   GET  /api/messages/sent           → messages I sent
 *   GET  /api/messages/unread-count   → the top-bar badge number
 *   POST /api/messages                → send (user | role | branch target)
 *   POST /api/messages/{uuid}/read    → mark one read
 *
 * Deliberately UNGATED beyond auth — internal mail for every portal
 * user (the spec gates only the device-announcement channel). Recipients
 * resolve at read time: target user, role group under the company team,
 * or everyone whose F5 branch scope includes the target branch.
 */
class PortalMessagesController extends Controller
{
    public function __construct(
        private readonly MerchantTenantContext $tenant,
        private readonly SendPortalMessageAction $send,
    ) {}

    public function inbox(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $perPage = min((int) $request->query('per_page', 25), 100);

        $page = PortalMessage::query()
            ->where('company_id', $this->tenant->requiredId())
            ->visibleTo($user, $this->roleNames($user))
            ->withExists(['reads as is_read' => fn ($q) => $q->where('user_id', $user->getKey())])
            ->with(['sender:id,name', 'targetBranch:id,uuid,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (PortalMessage $m): array => $this->map($m, includeRead: true));

        return response()->json($page);
    }

    public function sent(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $perPage = min((int) $request->query('per_page', 25), 100);

        $page = PortalMessage::query()
            ->where('company_id', $this->tenant->requiredId())
            ->where('sender_user_id', $user->getKey())
            ->withCount('reads')
            ->with(['sender:id,name', 'targetUser:id,name', 'targetBranch:id,uuid,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (PortalMessage $m): array => $this->map($m, includeReadCount: true));

        return response()->json($page);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $count = PortalMessage::query()
            ->where('company_id', $this->tenant->requiredId())
            ->visibleTo($user, $this->roleNames($user))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->getKey()))
            ->count();

        return response()->json(['data' => ['unread' => $count]]);
    }

    /**
     * Compose-picker data: teammates (id + name only — internal mail
     * needs a recipient list, like /api/branches feeds branch pickers)
     * and the company's role groups. Auth-only by design.
     */
    public function recipients(Request $request): JsonResponse
    {
        $this->user($request);
        $companyId = $this->tenant->requiredId();

        $users = User::query()
            ->merchant()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->all();

        $roles = \Spatie\Permission\Models\Role::query()
            ->where('team_id', $companyId)
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return response()->json(['data' => ['users' => $users, 'roles' => $roles]]);
    }

    public function store(SendPortalMessageRequest $request): JsonResponse
    {
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

        $message->load(['sender:id,name', 'targetUser:id,name', 'targetBranch:id,uuid,name']);

        return response()->json(['data' => $this->map($message)], 201);
    }

    public function read(Request $request, PortalMessage $message): JsonResponse
    {
        $user = $this->user($request);
        $this->refuseIfNotInTenant($message);

        // Only a RECIPIENT may mark it read (a foreign-but-in-tenant
        // message stays untouchable — and unleaked, since this 404s the
        // same as a wrong uuid would 404 on binding).
        $visible = PortalMessage::query()
            ->whereKey($message->getKey())
            ->visibleTo($user, $this->roleNames($user))
            ->exists();
        if (! $visible) {
            abort(404);
        }

        $message->reads()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['read_at' => now()],
        );

        return response()->json(['data' => ['read' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function map(PortalMessage $m, bool $includeRead = false, bool $includeReadCount = false): array
    {
        $row = [
            'uuid' => $m->uuid,
            'sender_name' => $m->sender?->name,
            'target_type' => $m->target_type,
            'target_user_name' => $m->targetUser?->name,
            'target_role' => $m->target_role,
            'target_branch' => $m->targetBranch !== null
                ? ['uuid' => $m->targetBranch->uuid, 'name' => $m->targetBranch->name]
                : null,
            'subject' => $m->subject,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
        if ($includeRead) {
            $row['is_read'] = (bool) $m->getAttribute('is_read');
        }
        if ($includeReadCount) {
            $row['read_count'] = (int) $m->getAttribute('reads_count');
        }

        return $row;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * The user's role names under the company team (the registrar is
     * already pinned by SetMerchantTenantContext on every request).
     *
     * @return list<string>
     */
    private function roleNames(User $user): array
    {
        return $user->getRoleNames()->values()->all();
    }

    private function refuseIfNotInTenant(PortalMessage $message): void
    {
        if ((int) $message->company_id !== $this->tenant->requiredId()) {
            abort(404);
        }
    }
}
