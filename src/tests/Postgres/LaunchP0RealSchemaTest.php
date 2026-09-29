<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LaunchP0RealSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('qr_fix4_p0', DB::connection()->getDatabaseName());
        $this->assertTrue(DB::table('pos_admin_migrations')->where('migration', '2026_09_30_000003_add_pos_user_auth_version')->exists());
        app(MerchantTenantContext::class)->set(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actor(string $role = 'Super Admin'): array
    {
        $company = Company::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        app(SeedMerchantRolesAction::class)->handle($company->id);
        app(MerchantTenantContext::class)->set($company->id);
        setPermissionsTeamId($company->id);
        $user = User::factory()->create(['company_id' => $company->id, 'user_type' => 'merchant', 'status' => 'active']);
        $user->assignRole($role);
        $this->actingAs($user);

        return [$company, $branch, $user];
    }

    public function test_tenant_scope_and_branch_restricted_collections_fail_closed(): void
    {
        [$company, $branch, $user] = $this->actor(MerchantRole::Manager->value);
        $other = Company::factory()->create();
        $foreign = Branch::factory()->create(['company_id' => $other->id]);
        $user->update(['branch_scope_json' => [$branch->id]]);
        foreach (['payouts', 'commission-invoices', 'purchase-receipts', 'portal-users'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->getJson('/api/reports/portion-variance?branch_ids[]='.$foreign->id)->assertForbidden();
        DB::table('pos_users')->where('id', $user->id)->update(['company_id' => null]);
        $this->getJson('/auth/user')->assertForbidden();
        $this->assertGuest();
        app(MerchantTenantContext::class)->set(null);
        request()->setRouteResolver(fn () => new Route('GET', '/api/products', fn () => null));
        $this->expectException(\LogicException::class);
        Product::count();
    }

    public function test_removed_user_and_suspended_company_lose_open_sessions(): void
    {
        [$company, $branch, $user] = $this->actor(MerchantRole::SuperAdmin->value);
        $this->getJson('/auth/user')->assertOk();
        $user->update(['status' => 'suspended']);
        $this->getJson('/auth/user')->assertUnauthorized();
        $this->assertGuest();
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        [$company, $branch, $user] = $this->actor(MerchantRole::SuperAdmin->value);
        $company->forceFill(['status' => 'suspended'])->save();
        $this->getJson('/auth/user')->assertForbidden()->assertJsonPath('code', 'company_suspended');
        $this->assertGuest();
    }
}
