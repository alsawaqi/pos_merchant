<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 7b — report CSV export coverage.
 *
 * GET /api/reports/{report}/export runs the report's Action and flattens the
 * multi-section payload to a CSV download. Gated on reports.export (distinct
 * from reports.view). Audit log is not exposed here.
 */
const EXPORT_WINDOW = 'date_from=2026-06-01&date_to=2026-06-30';

it('exports a report as a CSV download with its sections + data', function (): void {
    $ctx = makeMerchantActor(); // SuperAdmin → has reports.export

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000', 'discount_total' => '10.000', 'grand_total' => '90.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $res = $this->get('/api/reports/discounts/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])->assertOk();

    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->headers->get('content-disposition'))->toContain('attachment');
    expect($res->headers->get('content-disposition'))->toContain('discounts-report');

    $csv = $res->getContent();
    expect($csv)->toContain('# headline');       // summary block rendered
    expect($csv)->toContain('total_discount');
    expect($csv)->toContain('10.000');           // the data value
    expect($csv)->toContain('# by_branch');      // table block rendered
});

it('exports other report keys too', function (): void {
    makeMerchantActor();

    $res = $this->get('/api/reports/sales/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->getContent())->not->toBe('');

    $this->get('/api/reports/inventory-consumption/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="inventory-consumption-report_2026-06-01_to_2026-06-30.csv"');
});

it('404s an unknown report key', function (): void {
    makeMerchantActor();

    $this->get('/api/reports/nope/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('does not expose the audit log via the export endpoint', function (): void {
    makeMerchantActor();

    $this->get('/api/reports/audit-log/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])
        ->assertNotFound();
});

it('requires the reports.export permission (view is not enough)', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value); // has reports.view, NOT reports.export

    $this->get('/api/reports/discounts/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])
        ->assertForbidden();
});
