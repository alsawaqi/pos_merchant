<?php

declare(strict_types=1);

/**
 * Per-branch custom receipt template endpoint.
 *
 *   PUT /api/pos/branches/{uuid}/receipt-template  (branches.update)
 *
 * Covers: permission gate, normalize (empty → null, blanks dropped),
 * round-trip on the branch resource, tenant isolation, audit row.
 */

use App\Enums\MerchantRole;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('returns 403 without branches.update', function (): void {
    $ctx = makeMerchantActor();
    $ctx['user']->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/receipt-template", [
        'business_name' => 'X',
    ])->assertForbidden();
});

it('saves + normalizes the receipt template and echoes it back', function (): void {
    $ctx = makeMerchantActor(MerchantRole::SuperAdmin->value);

    $data = $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/receipt-template", [
        'business_name' => '  Aroma Cafe  ',
        'business_name_ar' => 'مقهى أروما',
        'cr_number' => 'CR-12345',
        'vat_number' => 'OM100200300',
        'address' => 'Al Khuwair, Muscat',
        'phone' => '+968 9000 0000',
        'header_lines' => ['Welcome', '   ', 'Dine-in & takeaway'],
        'footer_lines' => ['Thank you', ''],
        'show_qr' => false,
    ])->assertOk()->json('data.receipt_template');

    expect($data['business_name'])->toBe('Aroma Cafe');           // trimmed
    expect($data['business_name_ar'])->toBe('مقهى أروما');
    expect($data['cr_number'])->toBe('CR-12345');
    expect($data['vat_number'])->toBe('OM100200300');
    expect($data['header_lines'])->toBe(['Welcome', 'Dine-in & takeaway']); // blank dropped
    expect($data['footer_lines'])->toBe(['Thank you']);
    expect($data['show_qr'])->toBeFalse();

    // Persisted on the branch.
    $branch = Branch::query()->whereKey($ctx['branch']->id)->first();
    expect($branch->receipt_template['cr_number'])->toBe('CR-12345');
});

it('blank strings normalize to null', function (): void {
    $ctx = makeMerchantActor();

    $data = $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/receipt-template", [
        'business_name' => '',
        'cr_number' => '   ',
    ])->assertOk()->json('data.receipt_template');

    expect($data['business_name'])->toBeNull();
    expect($data['cr_number'])->toBeNull();
    expect($data['show_qr'])->toBeTrue(); // default
});

it('writes a branch.receipt_template.updated audit row', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/receipt-template", [
        'cr_number' => 'CR-999',
    ])->assertOk();

    expect(DB::table('pos_audit_logs')->where('event', 'branch.receipt_template.updated')->count())->toBe(1);
});

it('does not leak another tenant branch (404)', function (): void {
    makeMerchantActor();

    $foreign = Branch::factory()->for(Company::factory()->create(), 'company')->create();

    $this->putJson("/api/pos/branches/{$foreign->uuid}/receipt-template", [
        'cr_number' => 'CR-1',
    ])->assertNotFound();
});

it('rejects an over-long header line (422)', function (): void {
    $ctx = makeMerchantActor();

    $this->putJson("/api/pos/branches/{$ctx['branch']->uuid}/receipt-template", [
        'header_lines' => [str_repeat('x', 200)],
    ])->assertStatus(422)->assertJsonValidationErrors(['header_lines.0']);
});
