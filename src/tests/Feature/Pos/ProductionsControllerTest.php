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
use Illuminate\Support\Carbon;
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

    // Date range: only the two recent batches. seedProduction backdates
    // started_at by 45 minutes, so anchor the queried day to THAT moment
    // (running the suite within 45 minutes after midnight must not flake).
    $day = now()->subMinutes(45)->toDateString();
    $res = $this->getJson("/api/productions?from={$day}&to={$day}")->assertOk();
    expect($res->json('total'))->toBe(2);
});

it('rejects an invalid status filter', function (): void {
    makeMerchantActor();

    $this->getJson('/api/productions?status=exploded')->assertStatus(422);
});

it('rejects a time-bearing date filter on both endpoints (must be Y-m-d)', function (): void {
    makeMerchantActor();

    // A time component would concatenate into a malformed started_at literal
    // (a 500 on Postgres) — reject it at the edge with a clean 422 instead.
    $this->getJson('/api/productions?from=2026-06-15 14:30')->assertStatus(422);
    $this->getJson('/api/productions/summary?to=2026-06-15T10:00')->assertStatus(422);
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

/*
 * Graphical-view aggregates — GET /api/productions/summary (KP1).
 */

it('summarises production for the graphical view', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $ctx = makeMerchantActor();
    $cake = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked', 'name' => 'Cake', 'name_ar' => 'كيك']);
    $bread = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked', 'name' => 'Bread']);
    $chef = PosStaff::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->create(['name' => 'Chef Sami']);

    $base = ['company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id, 'started_by_staff_id' => $chef->id];
    seedProduction($base + ['product_id' => $cake->id, 'quantity' => '10.000', 'status' => 'finished', 'duration_seconds' => 1800]);
    seedProduction($base + ['product_id' => $cake->id, 'quantity' => '5.000', 'status' => 'finished', 'duration_seconds' => 3600]);
    seedProduction($base + ['product_id' => $bread->id, 'quantity' => '8.000', 'status' => 'in_progress', 'finished_at' => null, 'duration_seconds' => null]);

    $data = $this->getJson('/api/productions/summary')->assertOk()->json('data');

    expect($data['totals']['batches'])->toBe(3);
    expect($data['totals']['pieces'])->toBe('23.000');
    expect($data['totals']['finished'])->toBe(2);
    expect($data['totals']['in_progress'])->toBe(1);
    expect($data['totals']['cancelled'])->toBe(0);
    // AVG ignores the NULL duration of the in-progress batch: (1800 + 3600) / 2.
    expect($data['totals']['avg_duration_seconds'])->toBe(2700);

    // Top product by pieces: Cake (15) before Bread (8); name_ar carried.
    expect($data['by_product'])->toHaveCount(2);
    expect($data['by_product'][0]['product_name'])->toBe('Cake');
    expect($data['by_product'][0]['product_name_ar'])->toBe('كيك');
    expect($data['by_product'][0]['pieces'])->toBe('15.000');
    expect($data['by_product'][0]['batches'])->toBe(2);

    // By staff: one chef, all three batches.
    expect($data['by_staff'])->toHaveCount(1);
    expect($data['by_staff'][0]['staff_name'])->toBe('Chef Sami');
    expect($data['by_staff'][0]['batches'])->toBe(3);

    // Status mix.
    $mix = collect($data['status_mix'])->keyBy('status');
    expect($mix['finished']['count'])->toBe(2);
    expect($mix['in_progress']['count'])->toBe(1);

    // Timeline carries each batch start (for the Gantt).
    expect($data['timeline'])->toHaveCount(3);
    expect($data['timeline'][0]['started_at'])->not->toBeNull();
});

it('summary honours the branch + status filters', function (): void {
    $ctx = makeMerchantActor();
    $branchB = Branch::factory()->for($ctx['company'], 'company')->create();
    $product = Product::factory()->for($ctx['company'], 'company')->create(['stock_mode' => 'cooked']);

    $base = ['company_id' => $ctx['company']->id, 'product_id' => $product->id];
    seedProduction($base + ['branch_id' => $ctx['branch']->id, 'quantity' => '10.000', 'status' => 'finished']);
    seedProduction($base + ['branch_id' => $branchB->id, 'quantity' => '4.000', 'status' => 'finished']);

    // Branch filter narrows the totals to branch B only.
    $data = $this->getJson("/api/productions/summary?branch_uuid={$branchB->uuid}")->assertOk()->json('data');
    expect($data['totals']['batches'])->toBe(1);
    expect($data['totals']['pieces'])->toBe('4.000');
});

it('gates the summary behind production.view', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value);

    $this->getJson('/api/productions/summary')->assertForbidden();
});

it('summary does not leak another company production', function (): void {
    makeMerchantActor();

    $foreignCompany = Company::factory()->create();
    $foreignBranch = Branch::factory()->for($foreignCompany, 'company')->create();
    $foreignProduct = Product::factory()->for($foreignCompany, 'company')->create(['stock_mode' => 'cooked']);
    seedProduction([
        'company_id' => $foreignCompany->id,
        'branch_id' => $foreignBranch->id,
        'product_id' => $foreignProduct->id,
    ]);

    $data = $this->getJson('/api/productions/summary')->assertOk()->json('data');
    expect($data['totals']['batches'])->toBe(0);
    expect($data['by_product'])->toBe([]);
    expect($data['timeline'])->toBe([]);
});
