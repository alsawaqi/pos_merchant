<?php

declare(strict_types=1);

use App\Enums\MerchantRole;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

// ============================================================
// Phase D6 — format=csv|xlsx|pdf
// ============================================================

it('exports a report as XLSX (?format=xlsx)', function (): void {
    $ctx = makeMerchantActor();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000', 'discount_total' => '10.000', 'grand_total' => '90.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $res = $this->get('/api/reports/discounts/export?'.EXPORT_WINDOW.'&format=xlsx', ['Accept' => 'application/json'])
        ->assertOk();

    expect($res->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($res->headers->get('content-disposition'))
        ->toBe('attachment; filename="discounts-report_2026-06-01_to_2026-06-30.xlsx"');

    // XLSX is a zip container — PK\x03\x04 magic bytes.
    expect(substr((string) $res->getContent(), 0, 4))->toBe("PK\x03\x04");
});

it('exports a report as PDF (?format=pdf)', function (): void {
    $ctx = makeMerchantActor();

    Order::factory()->for($ctx['company'], 'company')->for($ctx['branch'], 'branch')->paid()->create([
        'subtotal' => '100.000', 'discount_total' => '10.000', 'grand_total' => '90.000',
        'opened_at' => '2026-06-15 12:00:00',
    ]);

    $res = $this->get('/api/reports/sales/export?'.EXPORT_WINDOW.'&format=pdf', ['Accept' => 'application/json'])
        ->assertOk();

    expect($res->headers->get('content-type'))->toContain('application/pdf');
    expect($res->headers->get('content-disposition'))
        ->toBe('attachment; filename="sales-report_2026-06-01_to_2026-06-30.pdf"');
    expect(substr((string) $res->getContent(), 0, 5))->toBe('%PDF-');
});

it('still exports CSV when ?format=csv is passed explicitly', function (): void {
    makeMerchantActor();

    $res = $this->get('/api/reports/sales/export?'.EXPORT_WINDOW.'&format=csv', ['Accept' => 'application/json'])
        ->assertOk();

    expect($res->headers->get('content-type'))->toContain('text/csv');
    expect($res->headers->get('content-disposition'))
        ->toBe('attachment; filename="sales-report_2026-06-01_to_2026-06-30.csv"');
});

it('422s an unknown export format', function (): void {
    makeMerchantActor();

    $this->get('/api/reports/sales/export?'.EXPORT_WINDOW.'&format=docx', ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['format']);
});

it('gates xlsx + pdf on reports.export like csv', function (): void {
    makeMerchantActor(MerchantRole::Viewer->value); // has reports.view, NOT reports.export

    $this->get('/api/reports/sales/export?'.EXPORT_WINDOW.'&format=xlsx', ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->get('/api/reports/sales/export?'.EXPORT_WINDOW.'&format=pdf', ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('exports every report key in every format', function (string $key): void {
    makeMerchantActor();

    foreach (['csv' => 'text/csv', 'xlsx' => 'spreadsheetml.sheet', 'pdf' => 'application/pdf'] as $format => $type) {
        $res = $this->get("/api/reports/{$key}/export?".EXPORT_WINDOW."&format={$format}", ['Accept' => 'application/json'])
            ->assertOk();
        expect($res->headers->get('content-type'))->toContain($type);
    }
})->with([
    'sales', 'customers', 'discounts', 'comps', 'shifts', 'product-performance',
    'recipe-cost', 'staff-activity', 'inventory-consumption', 'loss-waste',
    'restock-purchasing', 'round-up-donation', 'payouts',
]);

it('includes the monthly commission roll-up in the payouts export', function (): void {
    $ctx = makeMerchantActor();
    // One commissioned June sale → a by_month row.
    foreach ([['platform', '0.100', 0], ['bank', '0.090', 1], ['merchant', '2.810', 2]] as [$party, $amt, $sort]) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $ctx['company']->id, 'branch_id' => 10, 'device_id' => 1,
            'order_id' => 1, 'party_type' => $party, 'party_label' => ucfirst($party),
            'percent' => 0, 'gross_amount' => '3.000', 'commission_amount' => $amt,
            'sort_order' => $sort, 'occurred_at' => '2026-06-15 10:00:00',
            'created_at' => '2026-06-15 10:00:00', 'updated_at' => '2026-06-15 10:00:00',
        ]);
    }

    $csv = $this->get('/api/reports/payouts/export?'.EXPORT_WINDOW, ['Accept' => 'application/json'])
        ->assertOk()->getContent();

    expect($csv)->toContain('# by_month')   // the monthly section rendered
        ->and($csv)->toContain('2026-06')   // the month bucket
        ->and($csv)->toContain('2.810');    // merchant_net / finalized for the month
});
