<?php

declare(strict_types=1);

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('lists the devices the admin assigned to a branch, scoped to that branch', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];
    $other = Branch::factory()->for($ctx['company'], 'company')->create();
    $t = ['created_at' => now(), 'updated_at' => now()];

    DB::table('pos_devices')->insert([
        ['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $branch->id, 'name' => 'POS-1', 'device_type' => 'cashier', 'status' => 'active'] + $t,
        ['uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $other->id, 'name' => 'Other-POS', 'device_type' => 'cashier', 'status' => 'active'] + $t,
    ]);

    $res = $this->getJson("/api/pos/branches/{$branch->uuid}/devices")->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.name'))->toBe('POS-1');
    expect($res->json('data.0.device_type'))->toBe('cashier');
});

it('404s when listing devices for another company branch', function (): void {
    makeMerchantActor();
    $foreign = Branch::factory()->create(); // different company

    $this->getJson("/api/pos/branches/{$foreign->uuid}/devices")->assertNotFound();
});
