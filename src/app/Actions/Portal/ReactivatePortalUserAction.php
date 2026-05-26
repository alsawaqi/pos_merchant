<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Flip a teammate back to status=active. Idempotent on already-
 * active targets. No self-guard needed: a suspended user can't
 * log in to invoke this on themselves.
 *
 * Audit event: `portal_user.reactivated`.
 */
final readonly class ReactivatePortalUserAction
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

            if ($user->status === 'active') {
                return $user;
            }

            $previous = $user->status;
            $user->status = 'active';
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.reactivated',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: User::class,
                auditableId: $user->id,
                oldValues: ['status' => $previous],
                newValues: ['status' => 'active'],
            ));

            return $user;
        });
    }
}
