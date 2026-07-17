<?php

declare(strict_types=1);

/**
 * Phase B — merchant commission-invoice history (read-only).
 *
 *   GET /api/commission-invoices[?status=]          (reports.view) — own company only.
 *   GET /api/commission-invoices/{uuid}/lines       (reports.view) — own invoice only.
 *
 * Invoices are issued + collected by the platform (pos_admin); the merchant just
 * sees what they owe on cash/bank_pos sales. Tenant-scoped: a foreign invoice 404s.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedInvoiceRow(int $companyId, string $status, string $owed, string $createdAt): void
{
    DB::table('pos_commission_invoices')->insert([
        'uuid' => (string) Str::uuid(),
        'company_id' => $companyId,
        'period_from' => '2026-06-01 00:00:00',
        'period_to' => '2026-06-30 23:59:59',
        'status' => $status,
        'gross_amount' => '10.000',
        'platform_amount' => '0.200',
        'other_amount' => '0.100',
        'merchant_amount' => '9.700',
        'total_owed' => $owed,
        'sales_count' => 3,
        'reference' => $status === 'paid' ? 'REMIT-1' : null,
        'paid_at' => $status === 'paid' ? $createdAt : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/** An invoice with claimed cash sales (platform/other rows carry invoice_id). */
function seedClaimedInvoiceSale(int $companyId, int $orderId, int $branchId, string $platform, string $other, string $merchant, int $invoiceId): void
{
    $gross = number_format((float) $platform + (float) $other + (float) $merchant, 3, '.', '');
    $sort = 0;
    foreach (['platform' => $platform, 'other' => $other, 'merchant' => $merchant] as $party => $amount) {
        DB::table('pos_sale_commissions')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'device_id' => 1,
            'order_id' => $orderId, 'party_type' => $party, 'party_label' => ucfirst($party), 'percent' => 0,
            'gross_amount' => $gross, 'commission_amount' => $amount, 'sort_order' => $sort++,
            'invoice_id' => $party === 'merchant' ? null : $invoiceId,
            'occurred_at' => '2026-06-12 10:00:00', 'created_at' => '2026-06-12 10:00:00', 'updated_at' => '2026-06-12 10:00:00',
        ]);
    }
}

it('lists the company own commission invoices, newest first', function (): void {
    $ctx = makeMerchantActor();
    seedInvoiceRow($ctx['company']->id, 'paid', '0.290', '2026-06-01 10:00:00');
    seedInvoiceRow($ctx['company']->id, 'issued', '0.150', '2026-07-01 10:00:00');
    // Foreign company — must not leak.
    seedInvoiceRow(Company::factory()->create()->id, 'issued', '99.000', '2026-07-02 10:00:00');

    $rows = $this->getJson('/api/commission-invoices')->assertOk()->json('data');

    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBe('issued');       // newest first
    expect($rows[0]['total_owed'])->toBe('0.150');
    expect($rows[1]['status'])->toBe('paid');
    expect($rows[1]['total_owed'])->toBe('0.290');
    expect($rows[1]['reference'])->toBe('REMIT-1');
});

it('filters commission invoices by status', function (): void {
    $ctx = makeMerchantActor();
    seedInvoiceRow($ctx['company']->id, 'paid', '0.290', '2026-06-01 10:00:00');
    seedInvoiceRow($ctx['company']->id, 'issued', '0.150', '2026-07-01 10:00:00');

    $rows = $this->getJson('/api/commission-invoices?status=paid')->assertOk()->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['status'])->toBe('paid');
});

it('returns this company invoice per-branch breakdown (platform + other owed)', function (): void {
    $ctx = makeMerchantActor();
    $main = $ctx['branch'];
    $mall = \App\Models\Branch::factory()->for($ctx['company'], 'company')->create(['name' => 'Mall']);

    $uuid = (string) Str::uuid();
    $invoiceId = DB::table('pos_commission_invoices')->insertGetId([
        'uuid' => $uuid, 'company_id' => $ctx['company']->id,
        'period_from' => '2026-06-01 00:00:00', 'period_to' => '2026-06-30 23:59:59', 'status' => 'issued',
        'gross_amount' => '15.000', 'platform_amount' => '0.300', 'other_amount' => '0.100',
        'merchant_amount' => '14.600', 'total_owed' => '0.400', 'sales_count' => 2,
        'created_at' => '2026-06-30 10:00:00', 'updated_at' => '2026-06-30 10:00:00',
    ]);
    seedClaimedInvoiceSale($ctx['company']->id, 1, $main->id, '0.200', '0.100', '9.700', $invoiceId);
    seedClaimedInvoiceSale($ctx['company']->id, 2, $mall->id, '0.100', '0.000', '4.900', $invoiceId);

    $lines = $this->getJson("/api/commission-invoices/{$uuid}/lines")->assertOk()->json('data');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['branch_name'])->toBe($main->name)  // sorted by owed desc → 0.300 first
        ->and($lines[0]['total_owed'])->toBe('0.300')
        ->and($lines[0]['platform'])->toBe('0.200')
        ->and($lines[0]['other'])->toBe('0.100')
        ->and($lines[0]['merchant_kept'])->toBe('9.700')
        ->and($lines[0]['num_sales'])->toBe(1)
        ->and($lines[1]['branch_name'])->toBe('Mall')
        ->and($lines[1]['total_owed'])->toBe('0.100');
});

it('404s on another company invoice lines (tenant scope)', function (): void {
    makeMerchantActor();
    $other = Company::factory()->create();
    $uuid = (string) Str::uuid();
    DB::table('pos_commission_invoices')->insert([
        'uuid' => $uuid, 'company_id' => $other->id,
        'period_from' => '2026-06-01 00:00:00', 'period_to' => '2026-06-30 23:59:59', 'status' => 'issued',
        'gross_amount' => '5.000', 'platform_amount' => '0.100', 'other_amount' => '0.000',
        'merchant_amount' => '4.900', 'total_owed' => '0.100', 'sales_count' => 1,
        'created_at' => '2026-06-30 10:00:00', 'updated_at' => '2026-06-30 10:00:00',
    ]);

    $this->getJson("/api/commission-invoices/{$uuid}/lines")->assertNotFound();
});
