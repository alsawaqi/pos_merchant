<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Support\CanonicalPhone;
use App\Support\MerchantTenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses the shared canonical phone golden vectors', function (): void {
    foreach (json_decode(file_get_contents(base_path('tests/Fixtures/canonical-phone-vectors.json')), true, flags: JSON_THROW_ON_ERROR) as [$raw, $expected]) {
        expect(CanonicalPhone::of($raw))->toBe($expected);
    }
});

it('refuses canonical duplicate create but keeps existing duplicate name and format edits', function (): void {
    $ctx = makeMerchantActor();
    $first = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Existing Customer', 'phone' => '+968 9000 0001']);
    $second = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '96890000001']);
    $this->postJson('/api/customers', ['name' => 'New', 'phone' => '90000001'])->assertStatus(422);
    $this->patchJson('/api/customers/'.$second->uuid, ['name' => 'Still editable', 'phone' => '0096890000001'])->assertOk();
    expect($second->fresh()->name)->toBe('Still editable');
    $beforeCollision = DB::table('pos_customers')->orderBy('id')->get()->toJson();
    $auditBeforeCollision = DB::table('pos_audit_logs')->orderBy('id')->get()->toJson();
    $this->patchJson('/api/customers/'.$second->uuid, ['name' => 'Exact collision still editable', 'phone' => '+968 9000 0001'])->assertUnprocessable()
        ->assertJsonPath('message', 'That spelling is already used by Existing Customer. Keep the current number, or merge the two customers first.');
    expect($second->fresh()->name)->toBe('Still editable');
    expect(DB::table('pos_customers')->orderBy('id')->get()->toJson())->toBe($beforeCollision);
    expect(DB::table('pos_audit_logs')->orderBy('id')->get()->toJson())->toBe($auditBeforeCollision);
    expect($second->fresh()->phone)->toBe('0096890000001');
    expect(Customer::count())->toBe(2);
});

it('requires explicit pairs and an authorized actor and dry run changes nothing', function (): void {
    $ctx = makeMerchantActor();
    $a = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '+968 9000 0001']);
    $b = Customer::factory()->for($ctx['company'], 'company')->create(['phone' => '90000001']);
    $before = DB::table('pos_customers')->orderBy('id')->get()->toJson();
    $actor = auth()->id();
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $ctx['company']->id, '--actor' => $actor]))->toBe(0);
    expect(Artisan::output())->toContain('1 duplicate groups');
    expect(DB::table('pos_customers')->orderBy('id')->get()->toJson())->toBe($before);
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $ctx['company']->id, '--actor' => $actor, '--apply' => true]))->toBe(1);
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $ctx['company']->id, '--actor' => $actor, '--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(0);
    expect(Customer::withTrashed()->find($b->id)->merged_into_customer_id)->toBe($a->id);
    expect(Artisan::call('customers:merge-duplicates', ['--company' => $ctx['company']->id, '--actor' => $actor, '--apply' => true, '--only' => $a->id.':'.$b->id]))->toBe(0);
    expect(Artisan::output())->toContain('already_merged');
    expect(app(MerchantTenantContext::class)->id())->toBeNull();
});
