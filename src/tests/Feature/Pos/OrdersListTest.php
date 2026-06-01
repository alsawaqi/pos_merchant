<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists the company orders in the date window, tenant-scoped, with totals', function (): void {
    $ctx = makeMerchantActor();
    $branch = $ctx['branch'];

    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create(['opened_at' => '2026-06-15 10:00:00', 'grand_total' => '5.000']);
    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->paid()->create(['opened_at' => '2026-06-15 12:00:00', 'grand_total' => '3.000']);
    Order::factory()->for($ctx['company'], 'company')->for($branch, 'branch')->create(['opened_at' => '2026-05-01 12:00:00', 'grand_total' => '9.000']); // outside window
    Order::factory()->create(['opened_at' => '2026-06-15 12:00:00', 'grand_total' => '99.000']); // another company

    $res = $this->getJson('/api/orders?date_from=2026-06-01&date_to=2026-06-30')->assertOk();

    expect($res->json('data.rows'))->toHaveCount(2);
    expect($res->json('data.totals.count'))->toBe(2);
    expect($res->json('data.totals.grand_total'))->toBe('8.000'); // 5 + 3
});

it('filters orders by branch', function (): void {
    $ctx = makeMerchantActor();
    $b1 = $ctx['branch'];
    $b2 = Branch::factory()->for($ctx['company'], 'company')->create();

    Order::factory()->for($ctx['company'], 'company')->for($b1, 'branch')->create(['opened_at' => '2026-06-15 10:00:00']);
    Order::factory()->for($ctx['company'], 'company')->for($b2, 'branch')->create(['opened_at' => '2026-06-15 11:00:00']);

    $res = $this->getJson("/api/orders?date_from=2026-06-01&date_to=2026-06-30&branch_ids[]={$b1->id}")->assertOk();

    expect($res->json('data.rows'))->toHaveCount(1);
    expect($res->json('data.rows.0.branch_id'))->toBe($b1->id);
});
