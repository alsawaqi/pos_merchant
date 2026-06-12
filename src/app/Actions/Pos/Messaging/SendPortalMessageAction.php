<?php

declare(strict_types=1);

namespace App\Actions\Pos\Messaging;

use App\Models\Branch;
use App\Models\PortalMessage;
use App\Models\User;
use App\Support\BranchScope;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * P-G6 — send a portal-inbox message (portal → portal). Targets:
 *
 *   user     one teammate (by id, in-company merchant user);
 *   role     a role GROUP — every user holding the spatie role name
 *            under the company team (resolved at read time);
 *   branch   everyone whose F5 scope includes the branch.
 *
 * Open to every signed-in portal user (the spec gates only the device
 * channel) — internal mail, not a management surface. A branch-restricted
 * sender may only target branches within their own scope.
 */
final readonly class SendPortalMessageAction
{
    public function __construct(
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, User $actor): PortalMessage
    {
        $companyId = $this->tenant->requiredId();
        $targetType = (string) $attributes['target_type'];

        $targetUserId = null;
        $targetRole = null;
        $targetBranchId = null;

        if ($targetType === PortalMessage::TARGET_USER) {
            $exists = User::query()
                ->merchant()
                ->where('company_id', $companyId)
                ->whereKey((int) ($attributes['target_user_id'] ?? 0))
                ->exists();
            if (! $exists) {
                throw new RuntimeException('The selected teammate was not found.');
            }
            $targetUserId = (int) $attributes['target_user_id'];
        } elseif ($targetType === PortalMessage::TARGET_ROLE) {
            $roleName = (string) ($attributes['target_role'] ?? '');
            $roleExists = Role::query()
                ->where('team_id', $companyId)
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->exists();
            if (! $roleExists) {
                throw new RuntimeException('The selected role was not found.');
            }
            $targetRole = $roleName;
        } elseif ($targetType === PortalMessage::TARGET_BRANCH) {
            $branch = Branch::query()
                ->where('company_id', $companyId)
                ->where('uuid', (string) ($attributes['target_branch_uuid'] ?? ''))
                ->first();
            if ($branch === null) {
                throw new RuntimeException('The selected branch was not found.');
            }
            BranchScope::ensureBranch($actor, $branch);
            $targetBranchId = (int) $branch->id;
        } else {
            throw new RuntimeException('Unknown message target.');
        }

        return DB::transaction(fn (): PortalMessage => PortalMessage::query()->create([
            'company_id' => $companyId,
            'sender_user_id' => $actor->getKey(),
            'target_type' => $targetType,
            'target_user_id' => $targetUserId,
            'target_role' => $targetRole,
            'target_branch_id' => $targetBranchId,
            'subject' => $attributes['subject'] ?? null,
            'body' => (string) $attributes['body'],
        ]));
    }
}
