<?php

declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('F54 merged old phone refuses create without touching survivor or audit', function (): void {
    $ctx = makeMerchantActor();
    $survivor = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Survivor', 'phone' => '+968 9111 1111', 'date_of_birth' => '1990-01-01', 'tags_json' => ['VIP']]);
    $source = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Source', 'phone' => '90000001']);
    $this->postJson('/api/customers/'.$survivor->uuid.'/merge', ['source_uuid' => $source->uuid])->assertOk();
    $before = DB::table('pos_customers')->orderBy('id')->get()->toJson();
    $audit = DB::table('pos_audit_logs')->orderBy('id')->get()->toJson();
    $this->postJson('/api/customers', ['name' => 'New Person', 'phone' => '90000001'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Survivor') && str_contains($m, 'merged'));
    expect(DB::table('pos_customers')->orderBy('id')->get()->toJson())->toBe($before);
    expect(DB::table('pos_audit_logs')->orderBy('id')->get()->toJson())->toBe($audit);
});

it('F54 a nonmerged deleted exact phone is restored with explicit restore audit', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Deleted', 'phone' => '90000077', 'date_of_birth' => '1980-05-05', 'tags_json' => ['Old']]);
    $this->deleteJson('/api/customers/'.$customer->uuid)->assertNoContent();
    $this->postJson('/api/customers', ['name' => 'Restored', 'phone' => '90000077', 'tags' => ['New']])->assertCreated()->assertJsonPath('data.uuid', $customer->uuid);
    expect($customer->fresh()->name)->toBe('Restored')->and($customer->fresh()->tags_json)->toBe(['New'])->and($customer->fresh()->date_of_birth)->toBeNull()->and(Customer::withTrashed()->count())->toBe(1);
    expect(DB::table('pos_audit_logs')->where('event', 'customers.created')->where('auditable_id', $customer->id)->count())->toBe(0);
    expect(DB::table('pos_audit_logs')->where('event', 'customers.restored')->where('auditable_id', $customer->id)->count())->toBe(1);
});

it('F54 live create refusal preserves fields and audit', function (): void {
    $ctx = makeMerchantActor();
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Existing', 'phone' => '+968 9000 0001', 'date_of_birth' => '1990-01-01', 'tags_json' => ['VIP']]);
    $before = $customer->fresh()->getRawOriginal();
    $audit = DB::table('pos_audit_logs')->count();
    foreach (['+968 9000 0001', '90000001'] as $phone) {
        $this->postJson('/api/customers', ['name' => 'New', 'phone' => $phone])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Existing'));
        expect($customer->fresh()->getRawOriginal())->toBe($before);
        expect(DB::table('pos_audit_logs')->count())->toBe($audit);
    }
});

it('F56 colliding raw spelling refuses all edits and audit but other edits remain available', function (): void {
    $ctx = makeMerchantActor();
    Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Eighty-nine', 'phone' => '+968 9000 0001']);
    $customer = Customer::factory()->for($ctx['company'], 'company')->create(['name' => 'Ninety', 'phone' => '90000001']);
    $before = DB::table('pos_customers')->orderBy('id')->get()->toJson();
    $audit = DB::table('pos_audit_logs')->orderBy('id')->get()->toJson();
    foreach ([['phone' => '+968 9000 0001'], ['phone' => '+968 9000 0001', 'name' => 'Changed', 'tags' => ['New']]] as $payload) {
        $this->patchJson('/api/customers/'.$customer->uuid, $payload)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Eighty-nine') && str_contains($m, 'merge'));
        expect(DB::table('pos_customers')->orderBy('id')->get()->toJson())->toBe($before);
        expect(DB::table('pos_audit_logs')->orderBy('id')->get()->toJson())->toBe($audit);
    }
    $this->patchJson('/api/customers/'.$customer->uuid, ['phone' => '0096890000001'])->assertOk()->assertJsonPath('data.phone', '0096890000001');
    $this->patchJson('/api/customers/'.$customer->uuid, ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
});
