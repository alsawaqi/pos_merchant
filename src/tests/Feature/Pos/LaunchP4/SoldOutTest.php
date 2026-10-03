<?php

declare(strict_types=1);

/**
 * LAUNCH-P4 B4 — "Sold out" per branch in the portal (owner decision 4): a
 * manual switch, on every channel at that branch, until switched back; never
 * stock. Allowed with "Manage catalogue" or the new "Mark sold out" permission
 * (default Super Admin + Manager); branch-limited users only at their own
 * branches. A "Sold out" filter in the catalogue list, and the branch page
 * shows and switches it. Before: no switch, permission, filter or column.
 */

use App\Actions\Admin\SeedMerchantRolesAction;
use App\Enums\MerchantPermission;
use App\Enums\MerchantRole;
use App\Models\Company;
use App\Models\ProductSoldOut;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

/** A merchant user holding a custom role with exactly these permissions. */
function p4ActorWith(array $permissions): array
{
    $ctx = makeMerchantActor(MerchantRole::Viewer->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $role = Role::query()->create(['name' => 'Custom '.uniqid(), 'guard_name' => 'web', 'team_id' => $ctx['company']->id]);
    $role->syncPermissions($permissions);
    $ctx['user']->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $ctx;
}

it('marks an item sold out at one branch, who and when, and puts it back on sale', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $product = p4Product($ctx['company'], 'Mandi', '3.500');
    DB::table('pos_products')->where('id', $product->id)->update(['updated_at' => now()->subDay()]);

    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])
        ->assertOk()
        ->assertJsonPath('data.sold_out', true)
        ->assertJsonPath('data.sold_out_branch_ids', [$ctx['branch']->id]);

    $row = ProductSoldOut::query()->where('product_id', $product->id)->sole();
    expect($row->branch_id)->toBe($ctx['branch']->id)
        ->and($row->set_by_user_id)->toBe($ctx['user']->id)
        ->and($row->set_at)->not->toBeNull();
    // The product is touched so the device config re-emits it.
    expect(DB::table('pos_products')->where('id', $product->id)->value('updated_at'))->toBeGreaterThan(now()->subHour()->toDateTimeString());
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.product.sold_out', 'auditable_id' => $product->id, 'branch_id' => $ctx['branch']->id]);

    // Same state again: nothing new is written.
    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])->assertOk();
    expect(DB::table('pos_audit_logs')->where('event', 'catalogue.product.sold_out')->count())->toBe(1);

    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => false])
        ->assertOk()
        ->assertJsonPath('data.sold_out', false)
        ->assertJsonPath('data.sold_out_branch_ids', []);
    expect(ProductSoldOut::query()->where('product_id', $product->id)->exists())->toBeFalse();
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.product.back_on_sale', 'auditable_id' => $product->id]);
});

it('adds "Mark sold out" to the catalogue permissions, held by Super Admin and Manager', function (): void {
    expect(MerchantPermission::CatalogueSoldOut->value)->toBe('catalogue.sold_out.manage')
        ->and(PermissionCatalog::allMerchantKeys())->toContain('catalogue.sold_out.manage');

    $ctx = makeMerchantActor();
    $entry = collect(collect($this->getJson('/api/roles/catalog')->assertOk()->json('data'))->firstWhere('key', 'catalogue')['permissions'])
        ->firstWhere('key', 'catalogue.sold_out.manage');
    expect($entry['label_en'])->toStartWith('Mark sold out')->and($entry['label_ar'])->toStartWith('تحديد نفاد الصنف');

    app(SeedMerchantRolesAction::class)->handle($ctx['company']->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $holders = collect(MerchantRole::values())
        ->filter(fn (string $role): bool => Role::findByName($role, 'web')->hasPermissionTo('catalogue.sold_out.manage'))
        ->values()
        ->all();
    expect($holders)->toBe([MerchantRole::SuperAdmin->value, MerchantRole::Manager->value]);
});

it('lets a role with only "Mark sold out" switch it, but not a catalogue viewer', function (): void {
    $ctx = p4ActorWith(['catalogue.view', 'catalogue.sold_out.manage']);
    $product = p4Product($ctx['company'], 'Harees', '2.000');
    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])->assertOk();
    // ... and nothing else of the catalogue.
    $this->patchJson("/api/products/{$product->uuid}", ['name' => 'Renamed'])->assertForbidden();

    $viewer = makeMerchantActor(MerchantRole::Viewer->value);
    $other = p4Product($viewer['company'], 'Shuwa', '4.000');
    $this->putJson("/api/products/{$other->uuid}/sold-out", ['branch_id' => $viewer['branch']->id, 'sold_out' => true])->assertForbidden();
    expect(ProductSoldOut::query()->where('product_id', $other->id)->exists())->toBeFalse();
});

it('keeps a branch-limited user to their own branches', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $other = p4Branch($ctx['company'], 'Sohar');
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();
    $product = p4Product($ctx['company'], 'Majboos', '3.000');

    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])->assertOk();
    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $other->id, 'sold_out' => true])->assertForbidden();
    expect(ProductSoldOut::query()->where('product_id', $product->id)->pluck('branch_id')->all())->toBe([$ctx['branch']->id]);
});

it('refuses another company\'s branch or product', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Kabsa', '3.000');
    $foreign = Company::factory()->create();
    $foreignBranch = p4Branch($foreign, 'Elsewhere');
    $foreignProduct = p4Product($foreign, 'Theirs', '1.000');

    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $foreignBranch->id, 'sold_out' => true])->assertStatus(422);
    $this->putJson("/api/products/{$foreignProduct->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])->assertNotFound();
    expect(ProductSoldOut::query()->count())->toBe(0);
});

it('filters the catalogue to sold-out items and shows where each is sold out', function (): void {
    $ctx = makeMerchantActor();
    $out = p4Product($ctx['company'], 'Out', '1.000');
    p4Product($ctx['company'], 'In', '1.000');
    DB::table('pos_product_sold_out')->insert(['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'product_id' => $out->id, 'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $all = collect($this->getJson('/api/products')->json('data'))->keyBy('name');
    expect($all['Out']['sold_out_branch_ids'])->toBe([$ctx['branch']->id])
        ->and($all['In']['sold_out_branch_ids'])->toBe([]);

    $filtered = $this->getJson('/api/products?sold_out=1')->assertOk()->json('data');
    expect(collect($filtered)->pluck('name')->all())->toBe(['Out']);
    $atBranch = $this->getJson("/api/products?sold_out_branch={$ctx['branch']->id}")->assertOk()->json('data');
    expect(collect($atBranch)->pluck('name')->all())->toBe(['Out']);
});

it('shows and switches sold out on the branch page', function (): void {
    $ctx = makeMerchantActor();
    $product = p4Product($ctx['company'], 'Halwa', '1.500');

    $row = collect($this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/products")->assertOk()->json('data'))->firstWhere('name', 'Halwa');
    expect($row['sold_out'])->toBeFalse()->and($row['is_available'])->toBeTrue();

    $this->putJson("/api/products/{$product->uuid}/sold-out", ['branch_id' => $ctx['branch']->id, 'sold_out' => true])->assertOk();
    $row = collect($this->getJson("/api/pos/branches/{$ctx['branch']->uuid}/products")->json('data'))->firstWhere('name', 'Halwa');
    expect($row['sold_out'])->toBeTrue();
});
