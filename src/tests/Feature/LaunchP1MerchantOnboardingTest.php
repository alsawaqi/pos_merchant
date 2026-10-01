<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 part A, merchant portal side:
 *  - P1-13 (decision B7): an onboarding merchant signs in and works;
 *    only suspended / inactive block, each with its own message.
 *  - P1-14: a merchant with no branch yet can still sign in.
 *  - P1-2: the admin-issued set-password link is consumed on the
 *    /setup-password page (same endpoint as forgot/reset): password
 *    rules, force-change flag cleared, sessions ended, audited.
 */

use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * A merchant owner exactly as pos_admin provisions one: an empty
 * merchant_super_admin role under the company team.
 */
function p1MerchantOwner(Company $company, array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'company_id' => $company->id,
        'user_type' => 'merchant',
        'status' => 'active',
        'password' => 'Owner-password-1',
    ], $attributes));

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($company->id);
    $role = Role::query()->firstOrCreate([
        'name' => MerchantRole::SuperAdmin->value,
        'guard_name' => 'web',
        'team_id' => $company->id,
    ]);
    $user->assignRole($role);
    $registrar->forgetCachedPermissions();

    return $user;
}

/**
 * The row pos_admin's IssueSetPasswordLinkAction writes.
 */
function p1IssueLink(User $user, string $purpose, string $rawToken, int $minutes): void
{
    DB::table('pos_password_reset_tokens')->insert([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $rawToken),
        'purpose' => $purpose,
        'issued_by_user_id' => null,
        'expires_at' => now()->addMinutes($minutes),
        'created_at' => now(),
    ]);
}

it('lets a user of an onboarding merchant sign in and work', function (): void {
    $company = Company::factory()->create(['status' => 'onboarding']);
    $owner = p1MerchantOwner($company);

    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])
        ->assertOk()
        ->assertJsonPath('user.id', $owner->id);

    $this->getJson('/auth/user')->assertOk();
    $this->getJson('/auth/access')->assertOk();
    $this->getJson('/api/products')->assertOk();
});

it('lets an onboarding merchant with no branch yet sign in', function (): void {
    $company = Company::factory()->create(['status' => 'onboarding']);
    $owner = p1MerchantOwner($company);
    expect(DB::table('pos_branches')->where('company_id', $company->id)->exists())->toBeFalse();

    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])->assertOk();
    $this->getJson('/api/categories')->assertOk();
});

it('tells a closed (inactive) merchant it is closed, not suspended', function (): void {
    $company = Company::factory()->create(['status' => 'inactive']);
    $owner = p1MerchantOwner($company);

    $response = $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])
        ->assertUnprocessable();

    expect($response->json('errors.email.0'))->toContain('closed')
        ->not->toBe('Account suspended.');
    $this->assertGuest();
});

it('ends an open session with company_inactive when a merchant is closed', function (): void {
    $company = Company::factory()->create(['status' => 'onboarding']);
    $owner = p1MerchantOwner($company);
    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])->assertOk();

    DB::table('pos_companies')->where('id', $company->id)->update(['status' => 'inactive']);

    $this->getJson('/auth/user')->assertForbidden()->assertJsonPath('code', 'company_inactive');
});

it('still answers a suspended merchant with Account suspended', function (): void {
    $company = Company::factory()->create(['status' => 'suspended']);
    $owner = p1MerchantOwner($company);

    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Account suspended.');
});

it('serves the set-password page to a signed-out visitor', function (): void {
    $this->withoutVite();

    $this->get('/setup-password?token=abc&email=new%40cafe.test')
        ->assertOk()
        ->assertSee('<div id="app">', false);
});

it('sets a new merchant\'s first password from the admin invite link', function (): void {
    $company = Company::factory()->create(['status' => 'onboarding']);
    $owner = p1MerchantOwner($company, ['password' => null, 'must_change_password' => true]);
    $versionBefore = (int) DB::table('pos_users')->where('id', $owner->id)->value('auth_version');
    $raw = str_repeat('i', 64);
    p1IssueLink($owner, 'invite', $raw, 72 * 60);

    $this->postJson('/auth/reset-password', [
        'email' => $owner->email,
        'token' => $raw,
        'password' => 'My-first-password-9',
        'password_confirmation' => 'My-first-password-9',
    ])->assertOk();

    $fresh = DB::table('pos_users')->where('id', $owner->id)->first();
    expect(Hash::check('My-first-password-9', (string) $fresh->password))->toBeTrue()
        ->and((bool) $fresh->must_change_password)->toBeFalse()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);
    expect(DB::table('pos_password_reset_tokens')->where('user_id', $owner->id)->value('used_at'))->not->toBeNull();

    $audit = DB::table('pos_audit_logs')->where('event', 'portal_user.password_reset_completed')->sole();
    expect((string) $audit->new_values)->toContain('set_password_link');

    // The new password works straight away.
    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'My-first-password-9'])->assertOk();
});

it('ends the old sessions when an admin reset link is used', function (): void {
    $company = Company::factory()->create();
    $owner = p1MerchantOwner($company);
    $this->postJson('/auth/login', ['email' => $owner->email, 'password' => 'Owner-password-1'])->assertOk();
    $this->getJson('/auth/user')->assertOk();
    $raw = str_repeat('r', 64);
    p1IssueLink($owner, 'reset', $raw, 60);

    $this->postJson('/auth/reset-password', [
        'email' => $owner->email,
        'token' => $raw,
        'password' => 'Fresh-password-22',
        'password_confirmation' => 'Fresh-password-22',
    ])->assertOk();

    // The session opened with the old password is over.
    $this->getJson('/auth/user')->assertUnauthorized();

    $audit = DB::table('pos_audit_logs')->where('event', 'portal_user.password_reset_completed')->sole();
    expect((string) $audit->new_values)->toContain('admin_reset_link');
});

it('refuses a set-password that has no numbers', function (): void {
    $company = Company::factory()->create();
    $owner = p1MerchantOwner($company, ['password' => null]);
    $raw = str_repeat('w', 64);
    p1IssueLink($owner, 'invite', $raw, 72 * 60);

    $this->postJson('/auth/reset-password', [
        'email' => $owner->email,
        'token' => $raw,
        'password' => 'onlyletters',
        'password_confirmation' => 'onlyletters',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    expect(DB::table('pos_users')->where('id', $owner->id)->value('password'))->toBeNull();
});
