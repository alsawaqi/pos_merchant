<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Kitchen\Configuration;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kitchen.settings_enabled' => true, 'kitchen.activation_enabled' => false]);
    // Existing admin-owned legacy claim table is absent from the portal's old test mirror.
    if (! Schema::hasTable('pos_kitchen_tickets')) {
        Schema::create('pos_kitchen_tickets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('print_result')->nullable();
        });
    }
});

function kitchenBundle(): array
{
    $area = (string) Str::uuid();
    $printer = (string) Str::uuid();

    return ['areas' => [['id' => $area, 'name' => 'Grill']], 'destinations' => [['id' => $printer, 'name' => 'Kitchen printer', 'type' => 'printer', 'address' => '192.168.100.173', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$area], 'destinations' => [$printer]]];
}
function kitchenUrl(array $ctx, string $suffix = ''): string
{
    return '/api/settings/kitchen/branches/'.$ctx['branch']->uuid.$suffix;
}
function kitchenPolicy(array $input): array
{
    return test()->postJson('/api/settings/kitchen/policy-preview', $input)->assertOk()->json('data');
}
function kitchenPolicySave(array $input): void
{
    $preview = kitchenPolicy($input);
    test()->putJson('/api/settings/kitchen/policy', [...$input, 'preview_hash' => $preview['preview_hash']])->assertOk();
}

it('reads an empty branch without creating configuration or opting in', function (): void {
    $ctx = makeMerchantActor();
    $this->getJson('/api/settings/kitchen')->assertOk()->assertJsonPath('data.branches.0.mode', 'legacy');
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonPath('data.desired_version', 0)->assertJsonPath('data.activation_available', false);
    expect(DB::table('pos_kv2_branches')->count())->toBe(0);
});

it('previews and applies only one branch and source with session authority', function (): void {
    $ctx = makeMerchantActor();
    $b = Branch::factory()->for($ctx['company'], 'company')->create();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $input = ['branch_uuid' => $ctx['branch']->uuid, 'source' => 'qr_web', 'mode' => 'immediate', 'company_id' => $foreign->company_id, 'actor' => 'forged'];
    $preview = kitchenPolicy($input);
    expect($preview['branches'])->toHaveCount(1);
    $this->putJson('/api/settings/kitchen/policy', [...$input, 'preview_hash' => $preview['preview_hash']])->assertOk();
    expect(DB::table('pos_kv2_policies')->sole()->company_id)->toBe($ctx['company']->id);
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonPath('data.policies.qr_web', 'immediate')->assertJsonPath('data.policies.staff', 'manual')->assertJsonPath('data.applied_policies', null)->assertJsonPath('data.mode', 'legacy');
    expect(DB::table('pos_kv2_branches')->where('branch_id', $b->id)->count())->toBe(0)
        ->and(DB::table('pos_kv2_audit')->where('action', 'policy')->sole()->actor)->toBe('merchant:'.$ctx['user']->id);
});

it('all branches previews overrides and future inheritance without copying addresses', function (): void {
    $ctx = makeMerchantActor();
    $b = Branch::factory()->for($ctx['company'], 'company')->create();
    $bundle = kitchenBundle();
    $other = $bundle;
    $other['destinations'][0]['address'] = '192.168.100.200';
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertOk();
    $this->putJson('/api/settings/kitchen/branches/'.$b->uuid.'/routing', ['bundle' => $other, 'expected_version' => 0])->assertOk();
    kitchenPolicySave(['branch_uuid' => $b->uuid, 'source' => 'qr_web', 'mode' => 'manual']);
    kitchenPolicySave(['branch_uuid' => $b->uuid, 'source' => 'customer_tablet', 'mode' => 'immediate']);
    $input = ['branch_uuid' => null, 'source' => 'qr_web', 'mode' => 'immediate'];
    $preview = kitchenPolicy($input);
    expect($preview['future_branches'])->toBeTrue()->and($preview['branches'][1]['override_removed'])->toBeTrue();
    $this->putJson('/api/settings/kitchen/policy', [...$input, 'preview_hash' => $preview['preview_hash']])->assertOk();
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonPath('data.bundle.destinations.0.address', '192.168.100.173');
    $this->getJson('/api/settings/kitchen/branches/'.$b->uuid)->assertOk()->assertJsonPath('data.bundle.destinations.0.address', '192.168.100.200')->assertJsonPath('data.policies.customer_tablet', 'immediate');
    $new = Branch::factory()->for($ctx['company'], 'company')->create();
    $this->getJson('/api/settings/kitchen/branches/'.$new->uuid)->assertOk()->assertJsonPath('data.policies.qr_web', 'immediate')->assertJsonPath('data.mode', 'legacy');
});

it('rejects stale previews after settings or branch membership change', function (bool $newBranch): void {
    $ctx = makeMerchantActor();
    $input = ['branch_uuid' => null, 'source' => 'qr_web', 'mode' => 'immediate'];
    $preview = kitchenPolicy($input);
    if ($newBranch) {
        Branch::factory()->for($ctx['company'], 'company')->create();
    } else {
        $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => kitchenBundle(), 'expected_version' => 0])->assertOk();
    }
    $this->putJson('/api/settings/kitchen/policy', [...$input, 'preview_hash' => $preview['preview_hash']])->assertConflict()->assertJsonPath('errors.0.code', 'preview_changed');
    expect(DB::table('pos_kv2_policies')->count())->toBe(0);
})->with([true, false]);

it('enforces permission and branch scope for reads previews and writes', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $b = Branch::factory()->for($ctx['company'], 'company')->create();
    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $this->getJson('/api/settings/kitchen')->assertOk()->assertJsonCount(1, 'data.branches')->assertJsonPath('data.can_apply_all', false);
    foreach ([$b->uuid => 403, $foreign->uuid => 404] as $uuid => $code) {
        $url = '/api/settings/kitchen/branches/'.$uuid;
        $this->getJson($url)->assertStatus($code);
        $this->putJson($url.'/routing', ['bundle' => kitchenBundle(), 'expected_version' => 0])->assertStatus($code);
        $this->postJson($url.'/routing-preview', [])->assertStatus($code);
        $this->postJson($url.'/activate', [])->assertStatus($code);
        $this->postJson('/api/settings/kitchen/policy-preview', ['branch_uuid' => $uuid, 'source' => 'staff', 'mode' => 'immediate'])->assertStatus($code);
    }
    $this->postJson('/api/settings/kitchen/policy-preview', ['branch_uuid' => null, 'source' => 'staff', 'mode' => 'immediate'])->assertForbidden();
    kitchenPolicySave(['branch_uuid' => $ctx['branch']->uuid, 'source' => 'staff', 'mode' => 'immediate']);
});

it('viewer cannot mutate or preview configuration', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    $this->getJson(kitchenUrl($ctx))->assertOk();
    foreach (['policy-preview', 'branches/'.$ctx['branch']->uuid.'/routing-preview', 'branches/'.$ctx['branch']->uuid.'/activate'] as $path) {
        $this->postJson('/api/settings/kitchen/'.$path, [])->assertForbidden();
    }
    $this->putJson(kitchenUrl($ctx, '/routing'), [])->assertForbidden();
    $this->putJson('/api/settings/kitchen/policy', [])->assertForbidden();
    expect(DB::table('pos_kv2_branches')->count())->toBe(0);
});

it('requires authentication feature enablement and rejects paid release mode', function (): void {
    $this->getJson('/api/settings/kitchen')->assertUnauthorized();
    $ctx = makeMerchantActor();
    config(['kitchen.settings_enabled' => false]);
    $this->getJson('/api/settings/kitchen')->assertStatus(503);
    config(['kitchen.settings_enabled' => true]);
    $this->postJson('/api/settings/kitchen/policy-preview', ['branch_uuid' => $ctx['branch']->uuid, 'source' => 'qr_web', 'mode' => 'after_full_payment'])->assertUnprocessable();
});

it('saves desired routes only and rejects lost updates without destroying earlier snapshots', function (): void {
    $ctx = makeMerchantActor();
    $bundle = kitchenBundle();
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertOk()->assertJsonPath('data.desired_version', 1)->assertJsonPath('data.applied_version', 0)->assertJsonPath('data.pending', true);
    $original = DB::table('pos_kv2_configurations')->sole()->bundle;
    $bundle['destinations'][0]['address'] = '192.168.100.199';
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertConflict();
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 1])->assertOk();
    expect(DB::table('pos_kv2_configurations')->where('version', 1)->value('bundle'))->toBe($original);
    expect(DB::table('pos_kv2_branches')->sole()->mode)->toBe('legacy');
});

it('routing preview matches item precedence copies fallback and missing areas', function (): void {
    $ctx = makeMerchantActor();
    $cat = ProductCategory::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['category_id' => $cat->id]);
    $bundle = kitchenBundle();
    $p1 = $bundle['destinations'][0]['id'];
    $p2 = (string) Str::uuid();
    $area = $bundle['areas'][0]['id'];
    $bundle['destinations'][] = [...$bundle['destinations'][0], 'id' => $p2, 'name' => 'Pass'];
    $bundle['rules'] = [['kind' => 'category', 'reference_id' => $cat->id, 'areas' => [$area], 'destinations' => [$p1]], ['kind' => 'item', 'reference_id' => $product->id, 'areas' => [$area], 'destinations' => [$p2]]];
    $bundle['all_items'] = [$p1, $p2];
    $preview = $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertOk()->json('data');
    expect($preview['work'])->toHaveCount(1)->and($preview['copies'])->toHaveCount(2)->and($preview['copies'][$p1])->toHaveCount(1)->and($preview['copies'][$p2])->toHaveCount(1);
    $bundle['rules'] = [];
    $bundle['fallback'] = ['areas' => [], 'destinations' => []];
    $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertOk()->assertJsonCount(1, 'data.needs_routing');
    expect(DB::table('pos_kv2_submissions')->count())->toBe(0)->and(DB::table('pos_kv2_deliveries')->count())->toBe(0)->and(DB::table('pos_kv2_configurations')->count())->toBe(0);
});

it('rejects foreign catalogue references and malformed printer settings', function (): void {
    $ctx = makeMerchantActor();
    $foreign = Product::factory()->create();
    $bundle = kitchenBundle();
    $bundle['rules'][] = ['kind' => 'item', 'reference_id' => $foreign->id, 'areas' => [], 'destinations' => []];
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
    $bundle = kitchenBundle();
    $bundle['destinations'][0]['address'] = 'not-an-ip';
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
    expect(DB::table('pos_kv2_configurations')->count())->toBe(0);
});

it('default disabled activation cannot change legacy operation', function (): void {
    $ctx = makeMerchantActor();
    $bundle = kitchenBundle();
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertOk();
    $this->postJson(kitchenUrl($ctx, '/activate'), ['expected_version' => 1])->assertUnprocessable()->assertJsonPath('errors.0.code', 'kitchen_activation_unavailable');
    expect(DB::table('pos_kv2_branches')->sole()->mode)->toBe('legacy');
});

it('explicit opt in requires current enrollment confirmation and leaves activation pending', function (): void {
    $ctx = makeMerchantActor();
    config(['kitchen.activation_enabled' => true]);
    $bundle = kitchenBundle();
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertOk();
    $uuid = (string) Str::uuid();
    $id = DB::table('pos_devices')->insertGetId(['uuid' => $uuid, 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'device_type' => 'handheld', 'status' => 'active', 'assignment_activated_at' => now()]);
    $input = ['expected_version' => 1, 'device_uuid' => $uuid, 'confirm_opt_in' => true, 'isolation_evidence' => 'Synthetic test: prior executors isolated.'];
    $this->postJson(kitchenUrl($ctx, '/activate'), $input)->assertForbidden();
    app(Configuration::class)->enroll($ctx['company']->id, [$ctx['branch']->id], $id, str_repeat('a', 64), [$bundle['areas'][0]['id']], 'fixture');
    $this->postJson(kitchenUrl($ctx, '/activate'), [...$input, 'confirm_opt_in' => false])->assertUnprocessable();
    $this->postJson(kitchenUrl($ctx, '/activate'), $input)->assertOk()->assertJsonPath('data.mode', 'pending')->assertJsonPath('data.applied_version', 0);
    expect(DB::table('pos_kv2_audit')->where('action', 'assign')->sole()->actor)->toBe('merchant:'.$ctx['user']->id);
});

it('keeps current applied policy visible while a newer version is pending', function (): void {
    $ctx = makeMerchantActor();
    kitchenPolicySave(['branch_uuid' => $ctx['branch']->uuid, 'source' => 'qr_web', 'mode' => 'manual']);
    DB::table('pos_kv2_branches')->where('branch_id', $ctx['branch']->id)->update(['mode' => 'active', 'applied_version' => 1]);
    kitchenPolicySave(['branch_uuid' => $ctx['branch']->uuid, 'source' => 'qr_web', 'mode' => 'immediate']);
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonPath('data.pending', true)->assertJsonPath('data.applied_policies.qr_web', 'manual')->assertJsonPath('data.policies.qr_web', 'immediate');
    expect(DB::table('pos_kv2_submissions')->count())->toBe(0);
});

it('normalizes route reference numbers and rejects invalid nested fields without writes', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $bundle = kitchenBundle();
    $bundle['rules'] = [['kind' => 'item', 'reference_id' => (string) $product->id, 'areas' => $bundle['fallback']['areas'], 'destinations' => $bundle['fallback']['destinations']]];
    $result = $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertOk()->json('data');
    expect($result['work'])->toHaveCount(1);
    $bundle['rules'][0]['areas'] = [['injected' => 'field']];
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
    $bundle = kitchenBundle();
    $bundle['destinations'][0]['secret'] = 'must-not-be-stored';
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
    expect(DB::table('pos_kv2_configurations')->count())->toBe(0);
});

it('does not expose or accept catalogue items assigned only to another branch', function (): void {
    $ctx = makeMerchantActor();
    $branch = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['branch_scope' => 'selected']);
    DB::table('pos_branch_product')->insert(['branch_id' => $branch->id, 'product_id' => $product->id]);
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonCount(0, 'data.catalogue.products');
    $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => kitchenBundle(), 'expected_version' => 0, 'product_ids' => [$product->id]])->assertUnprocessable();
});

it('requires web CSRF on configuration mutations outside the test bypass', function (): void {
    $ctx = makeMerchantActor();
    $this->app->detectEnvironment(fn () => 'local');
    $this->postJson('/api/settings/kitchen/policy-preview', ['branch_uuid' => $ctx['branch']->uuid, 'source' => 'staff', 'mode' => 'immediate'])->assertStatus(419);
});

it('honors selected and all branch availability flags for route catalogue and writes', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    foreach (['selected', 'all'] as $scope) {
        $product = Product::factory()->for($ctx['company'], 'company')->create(['branch_scope' => $scope]);
        DB::table('pos_branch_product')->insert(['branch_id' => $ctx['branch']->id, 'product_id' => $product->id, 'is_available' => false]);
        $bundle = kitchenBundle();
        $bundle['rules'] = [['kind' => 'item', 'reference_id' => $product->id, 'areas' => $bundle['fallback']['areas'], 'destinations' => $bundle['fallback']['destinations']]];
        $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonCount(0, 'data.catalogue.products');
        $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertUnprocessable();
        $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
        DB::table('pos_branch_product')->where('product_id', $product->id)->update(['is_available' => true]);
        $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonCount(1, 'data.catalogue.products');
        DB::table('pos_branch_product')->where('product_id', $product->id)->update(['is_available' => false]);
    }
    expect(DB::table('pos_kv2_configurations')->count())->toBe(0);
});

it('treats empty category branch availability as all branches', function (): void {
    $ctx = makeMerchantActor();
    $category = ProductCategory::factory()->for($ctx['company'], 'company')->create(['branch_availability_json' => []]);
    $product = Product::factory()->for($ctx['company'], 'company')->create(['category_id' => $category->id]);
    $this->getJson(kitchenUrl($ctx))->assertOk()->assertJsonCount(1, 'data.catalogue.categories')->assertJsonCount(1, 'data.catalogue.products');
    $bundle = kitchenBundle();
    $bundle['rules'] = [['kind' => 'category', 'reference_id' => $category->id, 'areas' => $bundle['fallback']['areas'], 'destinations' => $bundle['fallback']['destinations']]];
    $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertOk()->assertJsonCount(1, 'data.work');
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertOk();
});

it('rejects objects where routing requires lists without storing uneditable configuration', function (string $path): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create();
    $bundle = kitchenBundle();
    $bundle['rules'] = [['kind' => 'item', 'reference_id' => $product->id, 'areas' => $bundle['fallback']['areas'], 'destinations' => $bundle['fallback']['destinations']]];
    $bundle['all_items'] = $bundle['fallback']['destinations'];
    data_set($bundle, $path, ['named' => data_get($bundle, $path.'.0')]);
    $this->putJson(kitchenUrl($ctx, '/routing'), ['bundle' => $bundle, 'expected_version' => 0])->assertUnprocessable();
    $this->postJson(kitchenUrl($ctx, '/routing-preview'), ['bundle' => $bundle, 'expected_version' => 0, 'product_ids' => [$product->id]])->assertUnprocessable();
    expect(DB::table('pos_kv2_configurations')->count())->toBe(0);
})->with(['areas', 'destinations', 'rules', 'all_items', 'fallback.areas', 'fallback.destinations', 'rules.0.areas', 'rules.0.destinations']);


it('versions KDS fallback per branch without changing old snapshots or accepting invalid minutes', function (): void {
    $ctx=makeMerchantActor();$bundle=kitchenBundle();$bundle['display']=['fallback_minutes'=>23];
    $this->putJson(kitchenUrl($ctx,'/routing'),['bundle'=>$bundle,'expected_version'=>0])->assertOk()->assertJsonPath('data.bundle.display.fallback_minutes',23);
    foreach ([0,241,1.5] as $invalid) {
        $bad=$bundle;$bad['display']['fallback_minutes']=$invalid;
        $this->putJson(kitchenUrl($ctx,'/routing'),['bundle'=>$bad,'expected_version'=>1])->assertUnprocessable();
    }
    $bundle['display']['fallback_minutes']=12;
    $this->putJson(kitchenUrl($ctx,'/routing'),['bundle'=>$bundle,'expected_version'=>1])->assertOk()->assertJsonPath('data.bundle.display.fallback_minutes',12)->assertJsonPath('data.applied_version',0);
    $original=DB::table('pos_kv2_configurations')->where('branch_id',$ctx['branch']->id)->where('version',1)->value('bundle');
    expect(json_decode($original,true)['display']['fallback_minutes'])->toBe(23);
});
