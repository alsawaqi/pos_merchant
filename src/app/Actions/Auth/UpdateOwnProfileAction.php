<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\User;

/**
 * Self-service profile update for the signed-in merchant portal
 * user (Phase D7).
 *
 * v1 deliberately edits ONLY the display name:
 *   - email is the login identifier (globally unique across both
 *     portals) and is admin-managed — changing it self-service
 *     would need a verification round-trip and breaks the
 *     admin-side audit trail, so it stays read-only here.
 *   - phone is encrypted PII managed through the Portal Users
 *     admin surface.
 */
final readonly class UpdateOwnProfileAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array{name: string}  $attributes  validated payload from UpdateProfileRequest
     */
    public function handle(User $user, array $attributes): User
    {
        $oldName = (string) $user->name;
        $newName = $attributes['name'];

        if ($oldName === $newName) {
            // No-op save: skip the write + the audit noise.
            return $user;
        }

        $user->forceFill(['name' => $newName])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'portal_user.profile_updated',
            actorUserId: (int) $user->id,
            companyId: $user->company_id === null ? null : (int) $user->company_id,
            auditableType: User::class,
            auditableId: (int) $user->id,
            oldValues: ['name' => $oldName],
            newValues: ['name' => $newName],
        ));

        return $user;
    }
}
