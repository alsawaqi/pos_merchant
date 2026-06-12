<?php

declare(strict_types=1);

/**
 * P-G7 — the Deliveries settlement page.
 *
 * Pending-verification delivery orders (punched no-tender on the device)
 * are confirmed/adjusted against the provider's statement here. Confirm
 * flips them to paid, RE-DATES them to the confirmation moment (revenue
 * counts when the money arrived), records the variance, and fires the
 * commission split on the RECEIVED amount. F5 scope + tenancy + the
 * permission matrix are asserted alongside.
 */

use App\Enums\MerchantRole;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** A provider + a pending-verification delivery order at [$branch]. */
function seedPendingDelivery(array $ctx, ?Branch $branch = null, array $orderOverrides = []): array
{
    $branch ??= $ctx['branch'];

    $provider = DeliveryProvider::query()->firstOrCreate(
        ['company_id' => $ctx['company']->id, 'name' => 'Talabat'],
        ['commission_percent' => 20, 'is_active' => true, 'sort_order' => 1],
    );
    $deviceId = DB::table('pos_devices')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id,
        'branch_id' => $branch->id,
        'name' => 'Till 1',
        'device_type' => 'cashier',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = Order::query()->create(array_merge([
        'company_id' => $ctx['company']->id,
        'branch_id' => $branch->id,
        'device_id' => $deviceId,
        'order_type' => 'delivery',
        'status' => OrderStatus::PendingVerification->value,
        'source' => 'main_pos',
        'subtotal' => '3.000',
        'grand_total' => '3.000',
        'opened_at' => now()->subDays(3),
        'delivery_provider_id' => $provider->id,
        'delivery_provider_name' => $provider->name,
        'delivery_reference' => 'TLB-1001',
        'delivery_commission_percent' => '20.00',
        'delivery_expected_payout' => '2.400',
        'delivery_punched_at' => now()->subDays(3),
    ], $orderOverrides));

    return ['provider' => $provider, 'order' => $order];
}

it('lists the pending queue with whole-set totals', function (): void {
    $ctx = makeMerchantActor();
    seedPendingDelivery($ctx);
    seedPendingDelivery($ctx, null, ['delivery_reference' => 'TLB-1002', 'grand_total' => '5.000', 'delivery_expected_payout' => '4.000']);

    $res = $this->getJson('/api/deliveries')->assertOk();
    expect($res->json('data'))->toHaveCount(2);
    expect($res->json('totals.count'))->toBe(2);
    expect($res->json('totals.punched_total'))->toBe('8.000');
    expect($res->json('totals.expected_total'))->toBe('6.400');
    expect($res->json('data.0.provider_name'))->toBe('Talabat');

    // Nothing confirmed yet.
    expect($this->getJson('/api/deliveries?status=confirmed')->assertOk()->json('data'))->toHaveCount(0);
});

it('bulk-confirms at the expected payout, re-dates revenue, and splits commission on the received amount', function (): void {
    $ctx = makeMerchantActor();
    $a = seedPendingDelivery($ctx);
    $b = seedPendingDelivery($ctx, null, ['delivery_reference' => 'TLB-1002']);

    // An active commission profile: platform 5%, merchant keeps the rest.
    $profileId = DB::table('pos_commission_profiles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id,
        'is_active' => true, 'merchant_percent' => 95,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('pos_commission_shares')->insert([
        ['commission_profile_id' => $profileId, 'party_type' => 'platform', 'label' => 'Platform', 'percent' => 5, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['commission_profile_id' => $profileId, 'party_type' => 'bank', 'label' => 'Bank', 'percent' => 2, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $res = $this->postJson('/api/deliveries/confirm', [
        'order_ids' => [$a['order']->id, $b['order']->id],
    ])->assertOk();
    expect($res->json('data.orders_confirmed'))->toBe(2);

    $order = $a['order']->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
    expect((string) $order->delivery_received_amount)->toBe('2.400');
    expect((string) $order->delivery_variance)->toBe('0.000');
    expect($order->delivery_confirmed_at)->not->toBeNull();
    expect((int) $order->delivery_confirmed_by_user_id)->toBe($ctx['user']->id);
    // Revenue re-dated to confirmation; the punch moment survives.
    expect($order->opened_at->isToday())->toBeTrue();
    expect($order->closed_at->isToday())->toBeTrue();
    expect($order->delivery_punched_at->isToday())->toBeFalse();

    // Commission on the RECEIVED 2.400: platform 5% = 0.120, bank 0 (no
    // card money), merchant remainder 2.280 — sums to the baisa.
    $rows = DB::table('pos_sale_commissions')->where('order_id', $order->id)->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(3);
    // sqlite hands raw decimals back float-ish — compare numerically.
    expect((float) $rows[0]->commission_amount)->toBe(0.120);
    expect((float) $rows[1]->commission_amount)->toBe(0.0);
    expect((float) $rows[2]->commission_amount)->toBe(2.280);
    expect($rows[2]->occurred_at)->not->toBeNull();

    // Idempotent: re-confirming is refused (no longer pending).
    $this->postJson('/api/deliveries/confirm', ['order_ids' => [$order->id]])->assertStatus(422);
    expect(DB::table('pos_sale_commissions')->where('order_id', $order->id)->count())->toBe(3);

    // The confirmed tab now shows both with received/variance totals.
    $confirmed = $this->getJson('/api/deliveries?status=confirmed')->assertOk();
    expect($confirmed->json('data'))->toHaveCount(2);
    expect($confirmed->json('totals.received_total'))->toBe('4.800');
    expect($confirmed->json('totals.variance_total'))->toBe('0.000');
});

it('adjusts a single order to the actual received amount with a variance', function (): void {
    $ctx = makeMerchantActor();
    $seeded = seedPendingDelivery($ctx);

    $this->postJson("/api/deliveries/{$seeded['order']->uuid}/adjust", [
        'received_amount' => '2.000',
    ])->assertOk();

    $order = $seeded['order']->refresh();
    expect($order->status)->toBe(OrderStatus::Paid);
    expect((string) $order->delivery_received_amount)->toBe('2.000');
    expect((string) $order->delivery_variance)->toBe('-0.400');
});

it('enforces the permission matrix and tenancy', function (): void {
    $ctx = makeMerchantActor();
    $seeded = seedPendingDelivery($ctx);
    $foreignOrderId = $seeded['order']->id;
    $foreignUuid = $seeded['order']->uuid;

    // A Viewer holds no deliveries.manage.
    $viewer = User::factory()->create([
        'company_id' => $ctx['company']->id, 'user_type' => 'merchant', 'status' => 'active',
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($ctx['company']->id);
    $viewer->assignRole(MerchantRole::Viewer->value);
    $this->actingAs($viewer);
    $this->getJson('/api/deliveries')->assertForbidden();
    $this->postJson('/api/deliveries/confirm', ['order_ids' => [$foreignOrderId]])->assertForbidden();

    // Another tenant can neither list nor decide on it.
    makeMerchantActor();
    expect($this->getJson('/api/deliveries')->assertOk()->json('data'))->toHaveCount(0);
    $this->postJson('/api/deliveries/confirm', ['order_ids' => [$foreignOrderId]])->assertStatus(422);
    $this->postJson("/api/deliveries/{$foreignUuid}/adjust", ['received_amount' => '1.000'])->assertNotFound();
    expect($seeded['order']->refresh()->status)->toBe(OrderStatus::PendingVerification);
});

it('applies the F5 branch scope to the list and refuses out-of-scope decisions wholesale', function (): void {
    $ctx = makeMerchantActor(MerchantRole::Manager->value);
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $inScope = seedPendingDelivery($ctx);
    $outOfScope = seedPendingDelivery($ctx, $branchB, ['delivery_reference' => 'TLB-2002']);
    $ctx['user']->forceFill(['branch_scope_json' => [$ctx['branch']->id]])->save();

    // The list silently shrinks to the scoped branch.
    $rows = $this->getJson('/api/deliveries')->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['reference'])->toBe('TLB-1001');

    // An explicit out-of-scope branch filter 403s.
    $this->getJson('/api/deliveries?branch_id='.$branchB->id)->assertForbidden();

    // A batch containing ANY out-of-scope order is refused before any flip.
    $this->postJson('/api/deliveries/confirm', [
        'order_ids' => [$inScope['order']->id, $outOfScope['order']->id],
    ])->assertForbidden();
    expect($inScope['order']->refresh()->status)->toBe(OrderStatus::PendingVerification);
    expect($outOfScope['order']->refresh()->status)->toBe(OrderStatus::PendingVerification);
});

it('guards provider deletion while deliveries await verification', function (): void {
    $ctx = makeMerchantActor();
    $seeded = seedPendingDelivery($ctx);

    $this->deleteJson("/api/delivery-providers/{$seeded['provider']->uuid}")->assertStatus(422);

    $this->postJson('/api/deliveries/confirm', ['order_ids' => [$seeded['order']->id]])->assertOk();
    $this->deleteJson("/api/delivery-providers/{$seeded['provider']->uuid}")->assertNoContent();
});

it('manages the provider commission percent through the existing CRUD', function (): void {
    makeMerchantActor();

    $created = $this->postJson('/api/delivery-providers', [
        'name' => 'Otlob', 'commission_percent' => 17.5,
    ])->assertCreated();
    expect($created->json('data.commission_percent'))->toBe('17.50');

    $uuid = $created->json('data.uuid');
    $updated = $this->patchJson("/api/delivery-providers/{$uuid}", [
        'commission_percent' => 22,
    ])->assertOk();
    expect($updated->json('data.commission_percent'))->toBe('22.00');
});
