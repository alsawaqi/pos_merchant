<?php

declare(strict_types=1);

namespace App\Actions\Portal;

use App\Enums\MerchantRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

final class AuthorizePortalUserManagement
{
    /** A lower role has a strict subset of the actor's effective permissions.
     * Incomparable/custom peers are refused; no arbitrary role-name ranking.
     */
    public function handle(User $actor, ?User $target = null, ?array $roles = null, ?array $grantScope = null, bool $changesScope = false): void
    {
        abort_unless($actor->user_type === 'merchant' && $actor->company_id !== null, 403, 'You may manage only users and grants strictly below your own access.');
        if ($target !== null) {
            abort_unless($target->user_type === 'merchant' && (int) $target->company_id === (int) $actor->company_id, 404);
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if ($target !== null) {
            $target->unsetRelation('roles')->unsetRelation('permissions');
        }
        $super = $actor->hasRole(MerchantRole::SuperAdmin->value);
        $actorPermissions = $actor->getAllPermissions()->pluck('name')->all();
        $actorScope = $actor->allowedBranchIds();
        if ($target !== null && ! $super) {
            abort_if($target->id === $actor->id || $target->hasRole(MerchantRole::SuperAdmin->value), 403, 'You may manage only users and grants strictly below your own access.');
            $this->assertLower($target->getAllPermissions()->pluck('name')->all(), $actorPermissions);
            $this->assertScope($actorScope, $target->allowedBranchIds());
        }
        if ($roles !== null) {
            $resolved = Role::query()->where('team_id', $actor->company_id)->where('guard_name', 'web')
                ->whereIn('name', $roles)->with('permissions')->get();
            abort_unless($resolved->count() === count(array_unique($roles)), 403, 'You may manage only users and grants strictly below your own access.');
            if (! $super) {
                abort_if(in_array(MerchantRole::SuperAdmin->value, $roles, true), 403, 'You may manage only users and grants strictly below your own access.');
                $this->assertLower($resolved->flatMap->permissions->pluck('name')->unique()->all(), $actorPermissions);
            }
        }
        if ($changesScope) {
            $this->assertScope($actorScope, $grantScope);
        }
    }

    private function assertLower(array $target, array $actor): void
    {
        abort_unless(array_diff($target, $actor) === [] && array_diff($actor, $target) !== [], 403, 'You may manage only users and grants strictly below your own access.');
    }

    private function assertScope(?array $actor, ?array $target): void
    {
        if ($actor !== null) {
            abort_unless($target !== null && array_diff($target, $actor) === [], 403, 'You may manage only users and grants strictly below your own access.');
        }
    }
}
