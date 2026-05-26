<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Flip a teammate to status=suspended (cannot log in).
 *
 * Refuses self-suspension and cross-tenant suspension. Idempotent
 * on already-suspended targets.
 *
 * Audit event: `portal_user.suspended`.
 */
final readonly class SuspendPortalUserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    public function handle(User $user, User $actor): User
    {
        return DB::transaction(function () use ($user, $actor): User {
            $companyId = $this->tenant->requiredId();

            if ($user->company_id !== $companyId) {
                abort(404);
            }

            if ($user->getKey() === $actor->getKey()) {
                throw new RuntimeException('You cannot suspend your own account.');
            }

            if ($user->status === 'suspended') {
                return $user;
            }

            $previous = $user->status;
            $user->status = 'suspended';
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.suspended',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: User::class,
                auditableId: $user->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => 'suspended'],
            ));

            return $user;
        });
    }
}
