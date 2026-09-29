<?php

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('W7 refuses a suspended company at password login and on an open session', function () {
    $ctx = makeMerchantActor();
    $ctx['company']->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/auth/user')->assertForbidden()->assertJsonPath('code', 'company_suspended');
    $this->assertGuest();
    $this->postJson('/auth/login', ['email' => $ctx['user']->email, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('W13 refuses null tenant users and throws on an unpinned merchant request', function () {
    $ctx = makeMerchantActor();
    DB::table('pos_users')->where('id', $ctx['user']->id)->update(['company_id' => null]);
    $this->getJson('/auth/user')->assertForbidden();
    $this->assertGuest();
    app(MerchantTenantContext::class)->set(null);
    request()->setRouteResolver(fn () => new Route('GET', '/api/products', fn () => null));
    expect(fn () => Product::count())->toThrow(LogicException::class);
});

it('W13 hides company-wide collections and rejects foreign report branches', function () {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->update(['branch_scope_json' => [$ctx['branch']->id]]);
    foreach (['payouts', 'commission-invoices', 'purchase-receipts', 'portal-users'] as $path) {
        $this->getJson('/api/'.$path)->assertForbidden();
    }
    $other = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $other->id]);
    $this->getJson('/api/reports/portion-variance?branch_ids[]='.$branch->id)->assertForbidden();
});

it('W13 returns the same generic invite error for duplicate emails in either tenant', function () {
    $ctx = makeMerchantActor();
    $other = Company::factory()->create();
    $errors = [];
    foreach ([$ctx['company']->id, $other->id] as $id) {
        $user = User::factory()->create(['company_id' => $id, 'user_type' => 'merchant']);
        $errors[] = $this->postJson('/api/portal-users', ['name' => 'Invite', 'email' => $user->email, 'role' => MerchantRole::Viewer->value])
            ->assertUnprocessable()->json('errors.email');
    }
    expect($errors[0])->toBe(['Unable to create this account with the supplied details.'])->and($errors[1])->toBe($errors[0]);
});
