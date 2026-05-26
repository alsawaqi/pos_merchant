<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generate a fresh 20-char password for a teammate and return it
 * once. Mirrors pos_admin's ResetMerchantUserPasswordAction but
 * scoped to the actor's company via MerchantTenantContext.
 *
 * Audit event: `portal_user.password_reset`. Password material
 * is NEVER logged.
 */
final readonly class ResetPortalUserPasswordAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
        private MerchantTenantContext $tenant,
    ) {}

    /**
     * @return array{user: User, plaintext_password: string}
     */
    public function handle(User $user, User $actor): array
    {
        $companyId = $this->tenant->requiredId();

        if ($user->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($user, $actor, $companyId): array {
            $plaintextPassword = Str::password(
                length: 20,
                letters: true,
                numbers: true,
                symbols: false,
                spaces: false,
            );

            $user->password = $plaintextPassword; // bcrypted via cast
            $user->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'portal_user.password_reset',
                actorUserId: $actor->getKey(),
                companyId: $companyId,
                auditableType: User::class,
                auditableId: $user->id,
                newValues: [
                    'reset_at' => now()->toIso8601String(),
                    'reset_by_side' => 'merchant_portal',
                ],
            ));

            return [
                'user' => $user,
                'plaintext_password' => $plaintextPassword,
            ];
        });
    }
}
