<?php

declare(strict_types=1);

/**
 * LAUNCH review add-on, part B item 5 — D3 physical-item containers (packs).
 *
 *   - a physical item's packs: "box holds 50 cups", nested "carton holds 4 ×
 *     box 50" (pieces = 200); whole numbers ≥ 2; the same word with another
 *     size is fine, the same name + size twice is not;
 *   - barcodes per pack and per piece;
 *   - Purchases: a pack line becomes pieces (packs × pieces per pack), the
 *     pieces may be lowered, never raised; no breakdown for physical items;
 *   - a used pack's size is locked; its name stays editable.
 */

use App\Models\Product;
use App\Models\ProductPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

function rvPhysical(\App\Models\Company $company, string $name = 'Cup'): Product
{
    return Product::factory()->for($company, 'company')->create([
        'name' => $name, 'is_internal' => true, 'stock_mode' => 'unit', 'base_price' => 0, 'internal_purpose' => 'packaging',
    ]);
}

it('keeps packs of a physical item, nested and with barcodes', function (): void {
    $ctx = makeMerchantActor();
    $cup = rvPhysical($ctx['company']);

    $box = $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'box', 'pieces' => 50, 'name_ar' => 'علبة'])->assertCreated()->json('data');
    expect($box['display_name'])->toBe('box 50 pcs')->and($box['display_name_ar'])->toBe('علبة 50 قطعة');

    $carton = $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'carton', 'contains_pack_uuid' => $box['uuid'], 'contains_quantity' => 4])->assertCreated()->json('data');
    expect($carton['pieces'])->toBe('200')->and($carton['display_name'])->toBe('carton (4 × box 50 pcs)');

    // The same word with another size is fine; the same name + size is not; 1 piece is not a pack.
    $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'box', 'pieces' => 100])->assertCreated();
    $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'Box', 'pieces' => 50])->assertStatus(422);
    $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'single', 'pieces' => 1])->assertStatus(422);

    // Barcodes per pack and per piece.
    $this->postJson('/api/inventory/barcodes', ['barcode' => '4001', 'item_type' => 'physical', 'item_uuid' => $cup->uuid, 'pack_uuid' => $box['uuid']])->assertCreated();
    $this->postJson('/api/inventory/barcodes', ['barcode' => '4002', 'item_type' => 'physical', 'item_uuid' => $cup->uuid])->assertCreated();

    $item = collect($this->getJson('/api/physical-items')->assertOk()->json('data'))->firstWhere('uuid', $cup->uuid);
    expect(collect($item['packs'])->pluck('display_name')->all())->toContain('box 50 pcs', 'carton (4 × box 50 pcs)')
        ->and(collect($item['packs'])->firstWhere('uuid', $box['uuid'])['barcodes'][0]['barcode'])->toBe('4001')
        ->and($item['barcodes'][0]['barcode'])->toBe('4002');

    // The box cannot go while the carton holds it.
    $this->deleteJson("/api/physical-items/{$cup->uuid}/packs/{$box['uuid']}")->assertStatus(422);
});

it('turns a pack on a purchase line into pieces, lowered but never raised', function (): void {
    $ctx = makeMerchantActor();
    $cup = rvPhysical($ctx['company']);
    $box = $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'box', 'pieces' => 50])->assertCreated()->json('data');

    $receipt = $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'product', 'item_uuid' => $cup->uuid, 'pack_uuid' => $box['uuid'], 'pieces' => '3', 'line_cost' => '15.000',
    ]]])->assertCreated();

    $line = $receipt->json('data.lines.0');
    expect($line['quantity'])->toBe('150.000')
        ->and($line['pieces'])->toBe('3')
        ->and($line['container_label'])->toBe('box 50 pcs');
    expect((float) DB::table('pos_product_stock')->where('product_id', $cup->id)->value('quantity'))->toBe(150.0);

    // Lowered (a torn box): 148 pieces. Raised: refused.
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'product', 'item_uuid' => $cup->uuid, 'pack_uuid' => $box['uuid'], 'pieces' => '3', 'amount' => '148', 'line_cost' => '15.000',
    ]]])->assertCreated()->assertJsonPath('data.lines.0.quantity', '148.000');
    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'product', 'item_uuid' => $cup->uuid, 'pack_uuid' => $box['uuid'], 'pieces' => '3', 'amount' => '151', 'line_cost' => '15.000',
    ]]])->assertStatus(422);

    // Used: the size is locked, the name is not.
    $this->patchJson("/api/physical-items/{$cup->uuid}/packs/{$box['uuid']}", ['pieces' => 40])->assertStatus(422);
    $this->patchJson("/api/physical-items/{$cup->uuid}/packs/{$box['uuid']}", ['name' => 'sleeve'])->assertOk()->assertJsonPath('data.display_name', 'sleeve 50 pcs');

    // No breakdown for physical items.
    expect(DB::table('pos_stock_container_balances')->count())->toBe(0);
});

it('never uses another item\'s pack', function (): void {
    $ctx = makeMerchantActor();
    $cup = rvPhysical($ctx['company']);
    $lid = rvPhysical($ctx['company'], 'Lid');
    $box = ProductPack::query()->create(['company_id' => $ctx['company']->id, 'product_id' => $lid->id, 'name' => 'box', 'pieces' => '100']);

    $this->postJson('/api/purchase-receipts', ['lines' => [[
        'item_type' => 'product', 'item_uuid' => $cup->uuid, 'pack_uuid' => $box->uuid, 'pieces' => '1', 'line_cost' => '1.000',
    ]]])->assertStatus(422);
    $this->postJson("/api/physical-items/{$cup->uuid}/packs", ['name' => 'carton', 'contains_pack_uuid' => $box->uuid, 'contains_quantity' => 2])->assertStatus(422);
});
