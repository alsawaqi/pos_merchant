<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantRole;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-shot data fixup for Phase 4.5.
 *
 * pos_admin's CreateMerchantUserAction only started assigning
 * the `merchant_super_admin` role AS OF the Phase 4.5 commit.
 * Every merchant user created before that commit therefore has
 * no role under their company team scope and would see a blank
 * Portal Users page (every action 403s).
 *
 * Run once after deploy:
 *
 *     php artisan merchant:backfill-super-admin
 *
 * Behaviour:
 *   - Iterates every `pos_users` row where user_type='merchant'.
 *   - For each, switches spatie's team_id to the user's company_id.
 *   - Seeds the role catalogue under that team via
 *     {@see SeedMerchantRolesAction} (idempotent).
 *   - Ensures the user has `merchant_super_admin` — only inserts
 *     when missing, never re-syncs (so a teammate who was
 *     intentionally demoted to Manager isn't promoted back).
 *
 * Safe to re-run: every step is idempotent and `--dry-run` reports
 * what WOULD change without writing.
 */
final class BackfillMerchantSuperAdminCommand extends Command
{
    protected $signature = 'merchant:backfill-super-admin {--dry-run : Report without writing}';

    protected $description = 'Assign merchant_super_admin to every existing merchant user that has no role yet.';

    public function handle(SeedMerchantRolesAction $seedRoles): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        $touched = 0;
        $skipped = 0;

        try {
            User::query()
                ->where('user_type', 'merchant')
                ->whereNotNull('company_id')
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($seedRoles, $registrar, $dryRun, &$touched, &$skipped): void {
                    foreach ($users as $user) {
                        /** @var User $user */
                        $companyId = (int) $user->company_id;
                        $registrar->setPermissionsTeamId($companyId);

                        // Make sure the role catalogue exists for
                        // this tenant. Free if already seeded.
                        if (! $dryRun) {
                            $seedRoles->handle($companyId);
                        }

                        // spatie's roles() relation already
                        // applies the team_id filter from the
                        // registrar — adding another where('team_id')
                        // here would collide with `pos_model_has_roles.team_id`
                        // and trigger an ambiguous-column error.
                        $hasAnyRole = $user->roles()->exists();
                        if ($hasAnyRole) {
                            $skipped++;
                            continue;
                        }

                        $this->line(sprintf(
                            '  → user #%d (%s) in company #%d — needs merchant_super_admin',
                            $user->id,
                            $user->email,
                            $companyId,
                        ));

                        if ($dryRun) {
                            $touched++;
                            continue;
                        }

                        $role = Role::query()->firstOrCreate([
                            'name' => MerchantRole::SuperAdmin->value,
                            'guard_name' => 'web',
                            'team_id' => $companyId,
                        ]);
                        $user->assignRole($role);
                        $touched++;
                    }
                });
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Done. {$verb} {$touched} user(s); {$skipped} already had a role.");

        return self::SUCCESS;
    }
}
