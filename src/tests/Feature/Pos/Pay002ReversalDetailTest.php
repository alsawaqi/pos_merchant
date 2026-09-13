<?php

declare(strict_types=1);

use App\Enums\ProductStockMovementType;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows tenant scoped reversal history and returned lines without bank details', function (): void {
    $ctx = makeMerchantActor();
    $order = Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create();
    $item = OrderItem::factory()->for($order, 'order')->create(['product_name_snapshot' => 'Cake']);
    $payment = Payment::factory()->create(['order_id' => $order->id, 'method' => 'card']);
    $staff = PosStaff::factory()->for($ctx['company'], 'company')->create(['name' => 'Approving manager']);
    $deviceId = DB::table('pos_devices')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
    ]);
    $id = DB::table('pos_payment_reversals')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'order_id' => $order->id, 'payment_id' => $payment->id, 'kind' => 'refund', 'amount' => '1.250',
        'amount_baisas' => 1250, 'currency_code' => '0512', 'status' => 'approved',
        'softpos_provider' => 'secret_provider', 'softpos_package' => 'secret_package', 'bank_id' => 999,
        'approved_by_staff_id' => $staff->id, 'device_id' => $deviceId, 'client_request_id' => (string) Str::uuid(),
        'request_fingerprint' => str_repeat('a', 64), 'attempted_at' => '2026-09-13 12:00:00',
        'completed_at' => '2026-09-13 12:01:00', 'response_code' => '00',
    ]);
    DB::table('pos_payment_reversal_lines')->insert([
        'reversal_id' => $id, 'order_item_id' => $item->id, 'qty' => 1, 'amount' => '1.250',
        'amount_baisas' => 1250, 'stock_mode_at_refund' => 'unit', 'returned_to_stock' => true,
    ]);
    $response = $this->getJson('/api/orders/'.$order->uuid)->assertOk()
        ->assertJsonCount(1, 'data.reversals')
        ->assertJsonPath('data.reversals.0.kind', 'refund')
        ->assertJsonPath('data.reversals.0.amount', '1.250')
        ->assertJsonPath('data.reversals.0.status', 'approved')
        ->assertJsonPath('data.reversals.0.approver', 'Approving manager')
        ->assertJsonPath('data.reversals.0.response_code', '00')
        ->assertJsonPath('data.reversals.0.completed_at', '2026-09-13 12:01:00')
        ->assertJsonPath('data.reversals.0.lines.0.product_name', 'Cake')
        ->assertJsonPath('data.reversals.0.lines.0.returned_to_stock', true);
    expect(array_keys($response->json('data.reversals.0')))->toBe([
        'uuid', 'kind', 'amount', 'status', 'approver', 'attempted_at', 'completed_at', 'response_code', 'lines',
    ]);
    expect($response->getContent())->not->toContain('secret_provider')->not->toContain('secret_package');
    $order->update(['company_id' => Company::factory()->create()->id]);
    $this->getJson('/api/orders/'.$order->uuid)->assertNotFound();
});

it('recognizes the refund return stock movement and renders its label', function (): void {
    expect(ProductStockMovementType::from('refund_return'))->toBe(ProductStockMovementType::RefundReturn);
    foreach (['en', 'ar'] as $locale) {
        $labels = json_decode(file_get_contents(resource_path('js/locales/'.$locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
        expect($labels['inventory']['movement_types']['refund_return'])->toBeString()->not->toBeEmpty();
        expect($labels['orders']['detail']['reversals'])->toBeString()->not->toBeEmpty();
    }
    expect(file_get_contents(resource_path('js/Pages/Merchant/Orders/components/OrderDetailDrawer.vue')))
        ->toContain('detail.reversals')->toContain('reversal.approver')->toContain('reversal.lines');
});
