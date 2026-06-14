<?php

declare(strict_types=1);

/**
 * Period-over-period sales comparison — GET /api/dashboard/sales-comparison.
 *
 * Compares the current week/month against the previous one, with a to-date %
 * change (fair while the current period is still in progress). Shared by the
 * dashboard (full scope) and the branch control center (?branch_id=<id>).
 */

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('compares the current week to the previous week with a to-date % change', function (): void {
    // Wednesday 2026-06-17 → this week Sun 06-14 .. Sat 06-20; 4 days elapsed.
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));
    $ctx = makeMerchantActor();

    // This week: one paid order on Mon 06-15.
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '20.000', 'opened_at' => Carbon::parse('2026-06-15 10:00:00'),
    ]);
    // Last week: one on Mon 06-08 (inside the first 4 to-date days) + one on Fri
    // 06-12 (a later day-offset — excluded from the to-date %, counted in full).
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '10.000', 'opened_at' => Carbon::parse('2026-06-08 10:00:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '5.000', 'opened_at' => Carbon::parse('2026-06-12 10:00:00'),
    ]);

    $data = $this->getJson('/api/dashboard/sales-comparison?period=week')->assertOk()->json('data');

    expect($data['period'])->toBe('week');
    expect($data['in_progress'])->toBeTrue();
    expect($data['current']['from'])->toBe('2026-06-14');
    expect($data['current']['to'])->toBe('2026-06-20');
    expect($data['current']['total'])->toBe('20.000');
    expect($data['previous']['from'])->toBe('2026-06-07');
    // To-date (first 4 days) excludes Fri 06-12 → only the 10 from Mon 06-08.
    expect($data['previous']['total'])->toBe('10.000');
    expect($data['previous']['full_total'])->toBe('15.000');
    expect((float) $data['change_pct'])->toBe(100.0); // JSON 100.0 decodes as int 100
    expect($data['current']['series'])->toHaveCount(7);
    expect($data['previous']['series'])->toHaveCount(7);
});

it('scopes the comparison to a requested branch', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));
    $ctx = makeMerchantActor();
    $other = Branch::factory()->for($ctx['company'], 'company')->create();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '20.000', 'opened_at' => Carbon::parse('2026-06-15 10:00:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($other, 'branch')->paid()->create([
        'grand_total' => '99.000', 'opened_at' => Carbon::parse('2026-06-15 10:00:00'),
    ]);

    $data = $this->getJson("/api/dashboard/sales-comparison?period=week&branch_id={$ctx['branch']->id}")
        ->assertOk()->json('data');

    // Only the requested branch's 20, never the other branch's 99.
    expect($data['current']['total'])->toBe('20.000');
});

it('compares calendar months when period=month', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));
    $ctx = makeMerchantActor();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '40.000', 'opened_at' => Carbon::parse('2026-06-05 10:00:00'),
    ]);
    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'grand_total' => '30.000', 'opened_at' => Carbon::parse('2026-05-05 10:00:00'),
    ]);

    $data = $this->getJson('/api/dashboard/sales-comparison?period=month')->assertOk()->json('data');

    expect($data['period'])->toBe('month');
    expect($data['current']['from'])->toBe('2026-06-01');
    expect($data['current']['to'])->toBe('2026-06-30');
    expect($data['current']['total'])->toBe('40.000');
    expect($data['previous']['from'])->toBe('2026-05-01');
    expect($data['previous']['total'])->toBe('30.000');
});
