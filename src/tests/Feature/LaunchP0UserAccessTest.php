<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
it('W5 refuses suspended password login', function () {
    $company = Company::factory()->create();
    $attrs = ['user_type' => 'merchant', 'company_id' => $company->id];
    $user = User::factory()->create($attrs + ['password' => 'test-password', 'status' => 'suspended']);
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'test-password'])->assertUnprocessable();
    $this->assertGuest();
});
it('W5 ends an open session on its next request and prevents its revival', function () {
    $company = Company::factory()->create();
    $attrs = ['user_type' => 'merchant', 'company_id' => $company->id];
    $user = User::factory()->create($attrs + ['password' => 'test-password']);
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk();
    $user->refresh()->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/auth/user')->assertUnauthorized();
    $user->refresh()->forceFill(['status' => 'active'])->save();
    $this->getJson('/auth/user')->assertUnauthorized();
});
