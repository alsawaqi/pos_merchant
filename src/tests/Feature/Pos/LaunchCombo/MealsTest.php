<?php

declare(strict_types=1);

/**
 * LAUNCH combo add-on, Part A item 3 (LAUNCH-COMBO_WORK_ORDER.md §1.4, §2.2):
 * the meal setup page's API — a name, a meal price, the categories of the
 * mains (new products join automatically), unticked mains, lines like a
 * combo's, optional limited-time dates; a main belongs to at most one
 * ACTIVE meal (the clash rule); every product and category is the
 * merchant's own.
 */

use App\Enums\MerchantRole;
use App\Models\ComboLine;
use App\Models\Company;
use App\Models\Meal;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

require_once __DIR__.'/../LaunchP4/helpers.php';

/** @return array<string, mixed> */
function cmMenu(Company $company): array
{
    $burgers = ProductCategory::factory()->for($company, 'company')->create(['name' => 'Burgers']);
    $drinks = ProductCategory::factory()->for($company, 'company')->create(['name' => 'Drinks']);

    return [
        'burgers' => $burgers, 'drinks' => $drinks,
        'beef' => p4Product($company, 'Beef burger', '2.000', ['category_id' => $burgers->id]),
        'chicken' => p4Product($company, 'Chicken burger', '1.800', ['category_id' => $burgers->id]),
        'fries' => p4Product($company, 'Fries', '1.000'),
        'loaded' => p4Product($company, 'Loaded fries', '1.500'),
        'cola' => p4Product($company, 'Cola', '1.000', ['category_id' => $drinks->id]),
        'juice' => p4Product($company, 'Juice', '1.200', ['category_id' => $drinks->id]),
    ];
}

/** @param array<string, mixed> $m */
function cmPayload(array $m, array $extra = []): array
{
    return array_merge([
        'name' => 'meal',
        'name_ar' => 'وجبة',
        'meal_price' => '1.200',
        'category_ids' => [$m['burgers']->id],
        'excluded_product_uuids' => [],
        'lines' => [
            ['kind' => 'fixed', 'product_uuid' => $m['fries']->uuid, 'quantity' => 1, 'upgrades' => [
                ['product_uuid' => $m['loaded']->uuid, 'upgrade_price' => '0.800'],
            ]],
            ['kind' => 'choice', 'name' => 'Drink', 'name_ar' => 'المشروب', 'category_id' => $m['drinks']->id, 'pick_count' => 1, 'items' => [
                ['product_uuid' => $m['juice']->uuid, 'excluded' => false, 'extra_price' => '0.300'],
            ]],
        ],
    ], $extra);
}

it('creates a meal on a category of mains with its price, lines and dates, and lists it', function (): void {
    $ctx = makeMerchantActor();
    $m = cmMenu($ctx['company']);

    $data = $this->postJson('/api/meals', cmPayload($m, ['on_sale_from' => '2026-11-01', 'on_sale_until' => '2026-11-30']))
        ->assertCreated()
        ->assertJsonPath('data.name', 'meal')
        ->assertJsonPath('data.meal_price', '1.200')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.category_ids', [$m['burgers']->id])
        ->assertJsonPath('data.mains_count', 2)
        ->assertJsonPath('data.on_sale_until', '2026-11-30')
        ->assertJsonPath('data.lines.0.kind', 'fixed')
        ->assertJsonPath('data.lines.0.upgrades.0.upgrade_price', '0.800')
        ->assertJsonPath('data.lines.1.pick_count', 1)
        ->json('data');

    $meal = Meal::query()->where('uuid', $data['uuid'])->sole();
    expect(ComboLine::query()->where('meal_id', $meal->id)->count())->toBe(2)
        ->and(DB::table('pos_meal_categories')->where('meal_id', $meal->id)->value('company_id'))->toBe($ctx['company']->id);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'catalogue.meal.saved', 'auditable_id' => $meal->id]);

    // A burger added to the category later joins the mains automatically.
    p4Product($ctx['company'], 'Veggie burger', '1.600', ['category_id' => $m['burgers']->id]);
    $this->getJson('/api/meals')->assertOk()->assertJsonPath('data.0.mains_count', 3);

    // Unticking a main, keeping the line ids.
    $payload = cmPayload($m, ['excluded_product_uuids' => [$m['chicken']->uuid]]);
    $payload['lines'][0]['id'] = $data['lines'][0]['id'];
    $this->putJson("/api/meals/{$data['uuid']}", $payload)->assertOk()
        ->assertJsonPath('data.excluded_product_uuids', [$m['chicken']->uuid])
        ->assertJsonPath('data.mains_count', 2)
        ->assertJsonPath('data.lines.0.id', $data['lines'][0]['id']);
});

it('refuses a meal that would share a main with another active meal, naming the clash', function (): void {
    $ctx = makeMerchantActor();
    $m = cmMenu($ctx['company']);
    $first = $this->postJson('/api/meals', cmPayload($m, ['name' => 'Burger meal']))->assertCreated()->json('data');

    $res = $this->postJson('/api/meals', cmPayload($m, ['name' => 'Big meal']))->assertStatus(422)->assertJsonValidationErrors(['category_ids']);
    expect(implode(' ', $res->json('errors.category_ids')))->toContain('"Beef burger" is already a main of the meal "Burger meal"')
        ->toContain('"Chicken burger" is already a main of the meal "Burger meal"');
    expect(Meal::query()->count())->toBe(1);

    // Inactive, it may overlap; activating it is refused again.
    $big = $this->postJson('/api/meals', cmPayload($m, ['name' => 'Big meal', 'status' => 'inactive']))->assertCreated()->json('data');
    $this->putJson("/api/meals/{$big['uuid']}", cmPayload($m, ['name' => 'Big meal', 'status' => 'active']))->assertStatus(422)
        ->assertJsonValidationErrors(['category_ids']);
    // Unticking both burgers in the first meal clears the clash... but then it has no main left.
    $this->putJson("/api/meals/{$first['uuid']}", cmPayload($m, ['name' => 'Burger meal', 'excluded_product_uuids' => [$m['beef']->uuid, $m['chicken']->uuid]]))
        ->assertStatus(422)->assertJsonValidationErrors(['category_ids']);
    // Unticking the chicken there and the beef in the big one works.
    $this->putJson("/api/meals/{$first['uuid']}", cmPayload($m, ['name' => 'Burger meal', 'excluded_product_uuids' => [$m['chicken']->uuid]]))->assertOk();
    $this->putJson("/api/meals/{$big['uuid']}", cmPayload($m, ['name' => 'Big meal', 'status' => 'active', 'excluded_product_uuids' => [$m['beef']->uuid]]))->assertOk();
    expect(Meal::query()->where('status', 'active')->count())->toBe(2);

    // A deleted meal no longer clashes.
    $this->deleteJson("/api/meals/{$first['uuid']}")->assertOk();
    $this->putJson("/api/meals/{$big['uuid']}", cmPayload($m, ['name' => 'Big meal', 'status' => 'active']))->assertOk();
    expect(Meal::withTrashed()->where('uuid', $first['uuid'])->sole()->trashed())->toBeTrue();
});

it('never uses another merchant\'s categories, products or meals', function (): void {
    $ctx = makeMerchantActor();
    $m = cmMenu($ctx['company']);
    $other = Company::factory()->create();
    $theirs = cmMenu($other);
    $theirMeal = Meal::query()->create(['company_id' => $other->id, 'name' => 'Their meal', 'meal_price' => '1.000']);

    $this->postJson('/api/meals', cmPayload($m, ['category_ids' => [$theirs['burgers']->id]]))->assertStatus(422)->assertJsonValidationErrors(['category_ids']);
    $this->postJson('/api/meals', cmPayload($m, ['excluded_product_uuids' => [$theirs['beef']->uuid]]))->assertStatus(422)->assertJsonValidationErrors(['excluded_product_uuids']);
    $payload = cmPayload($m);
    $payload['lines'][0]['product_uuid'] = $theirs['fries']->uuid;
    $this->postJson('/api/meals', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_uuid']);
    $payload = cmPayload($m);
    $payload['lines'][1]['category_id'] = $theirs['drinks']->id;
    $this->postJson('/api/meals', $payload)->assertStatus(422)->assertJsonValidationErrors(['lines.1.category_id']);
    $this->getJson("/api/meals/{$theirMeal->uuid}")->assertNotFound();
    $this->putJson("/api/meals/{$theirMeal->uuid}", cmPayload($m))->assertNotFound();
    $this->deleteJson("/api/meals/{$theirMeal->uuid}")->assertNotFound();
    expect(collect($this->getJson('/api/meals')->json('data'))->pluck('uuid')->all())->toBe([])
        ->and(Meal::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('checks the meal fields: price, dates, a category, at least one line', function (): void {
    $ctx = makeMerchantActor();
    $m = cmMenu($ctx['company']);

    $this->postJson('/api/meals', cmPayload($m, ['meal_price' => '-0.100']))->assertStatus(422)->assertJsonValidationErrors(['meal_price']);
    $this->postJson('/api/meals', cmPayload($m, ['on_sale_from' => '2026-11-10', 'on_sale_until' => '2026-11-09']))->assertStatus(422)->assertJsonValidationErrors(['on_sale_until']);
    $this->postJson('/api/meals', cmPayload($m, ['category_ids' => []]))->assertStatus(422)->assertJsonValidationErrors(['category_ids']);
    $this->postJson('/api/meals', cmPayload($m, ['lines' => []]))->assertStatus(422)->assertJsonValidationErrors(['lines']);
    $this->postJson('/api/meals', cmPayload($m, ['name' => '']))->assertStatus(422)->assertJsonValidationErrors(['name']);
    expect(Meal::query()->count())->toBe(0);
});

it('lets a catalogue viewer read meals but not save them', function (): void {
    $owner = makeMerchantActor();
    $m = cmMenu($owner['company']);
    $uuid = $this->postJson('/api/meals', cmPayload($m))->assertCreated()->json('data.uuid');

    $viewer = User::factory()->create(['company_id' => $owner['company']->id, 'user_type' => 'merchant', 'status' => 'active']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($owner['company']->id);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $this->actingAs($viewer);
    $this->getJson("/api/meals/{$uuid}")->assertOk()->assertJsonPath('data.name', 'meal');
    $this->putJson("/api/meals/{$uuid}", cmPayload($m))->assertForbidden();
    $this->postJson('/api/meals', cmPayload($m, ['name' => 'Other']))->assertForbidden();
    $this->deleteJson("/api/meals/{$uuid}")->assertForbidden();
    expect(Product::query()->count())->toBeGreaterThan(0);
});
