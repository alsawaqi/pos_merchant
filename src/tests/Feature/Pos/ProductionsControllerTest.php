<?php

declare(strict_types=1);

/**
 * P-G1 — kitchen production history (read-only).
 *
 *   GET /api/productions  (production.view)
 *
 * Batches are written by pos_api from the device Kitchen screen; this
 * page audits them. Covers: listing with lines + staff names, the
 * branch / status / date filters, the production.view gate, and
 * cross-tenant isolation.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosStaff;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Insert a production batch + its lines directly (the merchant models are
 * read-only mirrors; pos_api is the real writer).
 *
 * @param  array<int, array{ingredient_id: int, quantity: string, is_extra?: bool}>  $lines
 */
function seedProduction(array $attributes, array $lines = []): int
{
    $now = now();

    $id = DB::table('pos_productions')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'quantity' => '10.000',
        'status' => 'finished',
        'started_at' => $now->copy()->subMinutes(45),
        'finished_at' => $now,
        'duration_seconds' => 2700,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attributes));

    foreach ($lines as $line) {
        DB::table('pos_production_lines')->insert([
            'production_id' => $id,
            'ingredient_id' => $line['ingredient_id'],
            'quantity' => $line['quantity'],
            'unit_at_time' => $line['unit_at_time'] ?? 'kg',
            'is_extra' => $line['is_extra'] ?? false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return $id;
}

it('lists production batches with lines and staff names', function (): void {
    $ctx = makeMerchantActor();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked', 'name' => 'Cake']);
    $ingredient = Ingredient::factory()->for($ctx['company'], 'company')->create(['name' => 'Flour', 'unit' => 'kg']);
    $chef = PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['name' => 'Chef Sami']);

    seedProduction([
        'company_id' => $ctx['company']->id,
        'branch_id' => $ctx['branch']->id,
        'product_id' => $product->id,
        'started_by_staff_id' => $chef->id,
        'finished_by_staff_id' => $chef->id,
        // P-G1.5 — the chef's batch expiry.
        'expires_at' => now()->addDay(),
    ], [
        ['ingredient_id' => $ingredient->id, 'quantity' => '5.000'],
        ['ingredient_id' => $ingredient->id, 'quantity' => '0.250', 'is_extra' => true],
    ]);

    $res = $this->getJson('/api/productions')->assertOk();

    expect($res->json('total'))->toBe(1);
    $row = $res->json('data.0');
    expect($row['product']['name'])->toBe('Cake');
    expect($row['branch']['name'])->toBe($ctx['branch']->name);
    expect($row['quantity'])->toBe('10.000');
    expect($row['status'])->toBe('finished');
    expect($row['started_by'])->toBe('Chef Sami');
    expect($row['finished_by'])->toBe('Chef Sami');
    expect($row['duration_seconds'])->toBe(2700);
    expect($row['expires_at'])->not->toBeNull();
    expect($row['lines'])->toHaveCount(2);
    expect($row['lines'][0]['ingredient_name'])->toBe('Flour');
    expect($row['lines'][0]['is_extra'])->toBeFalse();
    expect($row['lines'][1]['is_extra'])->toBeTrue();
    expect($row['lines'][1]['quantity'])->toBe('0.250');
});

it('filters by branch, status, and date range', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked']);

    $base = ['company_id' => $ctx['company']->id, 'product_id' => $product->id];
    seedProduction($base + ['branch_id' => $ctx['branch']->id, 'status' => 'finished']);
    seedProduction($base + ['branch_id' => $branchB->id, 'status' => 'in_progress', 'finished_at' => null, 'duration_seconds' => null]);
    seedProduction($base + [
        'branch_id' => $ctx['branch']->id,
        'status' => 'cancelled',
        'started_at' => now()->subDays(10),
        'finished_at' => null,
        'cancelled_at' => now()->subDays(10),
        'duration_seconds' => null,
    ]);

    // Branch filter.
    $res = $this->getJson("/api/productions?branch_uuid={$branchB->uuid}")->assertOk();
    expect($res->json('total'))->toBe(1);
    expect($res->json('data.0.status'))->toBe('in_progress');

    // Status filter.
    $res = $this->getJson('/api/productions?status=cancelled')->assertOk();
    expect($res->json('total'))->toBe(1);

    // Date range: only the two recent batches started today.
    $today = now()->toDateString();
    $res = $this->getJson("/api/productions?from={$today}&to={$today}")->assertOk();
    expect($res->json('total'))->toBe(2);
});

it('rejects an invalid status filter', function (): void {
    makeMerchantActor();

    $this->getJson('/api/productions?status=exploded')->assertStatus(422);
});

it('gates the page behind production.view', function (): void {
    // Viewer was never granted production.view.
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/productions')->assertForbidden();
});

it('does not leak another company productions', function (): void {
    $ctx = makeMerchantActor();

    $foreignCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($foreignCompany, 'company')->create();
    $foreignProduct = Product::factory()->for($foreignCompany, 'company')->create(['stock_mode' => 'cooked']);
    seedProduction([
        'company_id' => $foreignCompany->id,
        'branch_id' => $foreignBranch->id,
        'product_id' => $foreignProduct->id,
    ]);

    expect($this->getJson('/api/productions')->assertOk()->json('total'))->toBe(0);

    // A foreign branch uuid in the filter matches nothing (no 500, no leak).
    expect($this->getJson("/api/productions?branch_uuid={$foreignBranch->uuid}")->assertOk()->json('total'))
        ->toBe(0);
});
