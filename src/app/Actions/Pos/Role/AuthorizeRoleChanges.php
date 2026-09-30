<?php

declare(strict_types=1);

namespace App\Actions\Pos\Role;

use App\Actions\Portal\AuthorizePortalUserManagement;
use App\Enums\MerchantRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

final class AuthorizeRoleChanges
{
    public function handle(User $actor, array $permissions, ?Role $role = null): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        abort_unless($actor->user_type === 'merchant' && $actor->company_id !== null, 403, 'Merchant role management is required.');
        if ($role !== null) {
            abort_unless((int) $role->team_id === (int) $actor->company_id, 404);
        }
        if ($actor->hasRole(MerchantRole::SuperAdmin->value)) {
            return;
        }
        $own = $actor->getAllPermissions()->pluck('name')->all();
        $this->lower($permissions, $own);
        if ($role === null) {
            return;
        }
        // Even a metadata edit of a privileged role is owner-only.
        $this->lower($role->permissions()->pluck('name')->all(), $own);
        foreach ($role->users()->where('company_id', $actor->company_id)->orderBy('pos_users.id')->lockForUpdate()->get() as $target) {
            app(AuthorizePortalUserManagement::class)->handle($actor, $target);
            $other = $target->getDirectPermissions()->pluck('name')->all();
            foreach ($target->roles()->where('pos_roles.id', '<>', $role->id)->with('permissions')->get() as $remaining) {
                $other = array_merge($other, $remaining->permissions->pluck('name')->all());
            }
            $this->lower(array_values(array_unique(array_merge($other, $permissions))), $own);
        }
    }

    private function lower(array $proposed, array $own): void
    {
        abort_unless(! in_array('roles.manage', $proposed, true)
            && array_diff($proposed, $own) === [] && array_diff($own, $proposed) !== [], 403,
            'Only Super Admin may edit privileged roles. Other roles must remain strictly below your permissions.');
    }
}
