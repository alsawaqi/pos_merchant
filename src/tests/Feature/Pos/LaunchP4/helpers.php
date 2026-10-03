<?php

declare(strict_types=1);

/*
 * LAUNCH-P4 Part B test fixtures shared by the tests in this folder (required,
 * not a test file). Every helper is prefixed p4 so it never collides with the
 * suite's other global helpers. Fixtures write the tables directly (not the
 * models) so they mean the same whatever application code runs — the
 * fail-before run uses the launch-p4 base (0d03668) with the P4 test schema.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! function_exists('p4RegisterVat')) {
    /** Mark the company VAT-registered the way pos_admin's onboarding does. */
    function p4RegisterVat(Company $company, string $number = 'OM1100223344', string $since = '2026-01-01'): void
    {
        DB::table('pos_companies')->where('id', $company->id)->update([
            'vat_number' => $number,
            'vat_registered_at' => $since,
        ]);
    }

    /** A product row with the P4 columns set directly. */
    function p4Product(Company $company, string $name, string $price, array $extra = []): Product
    {
        $id = DB::table('pos_products')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $company->id,
            'name' => $name,
            'base_price' => $price,
            'stock_mode' => 'untracked',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        return Product::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /** A second branch of the same company. */
    function p4Branch(Company $company, string $name): Branch
    {
        return Branch::factory()->for($company, 'company')->create(['name' => $name]);
    }

    /**
     * A paid order written straight to the tables. $lines: list of
     * [product, qty, unit_price, line_total, extra] (extra = more pos_order_items
     * columns, e.g. parent_order_item_id). Returns [order_id, [line ids]].
     *
     * @param  list<array{0: Product, 1: string, 2: string, 3: string, 4?: array<string, mixed>}>  $lines
     * @param  array<string, mixed>  $order
     * @return array{0: int, 1: list<int>}
     */
    function p4PaidOrder(Branch $branch, array $order, array $lines): array
    {
        $orderId = DB::table('pos_orders')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'order_type' => 'quick',
            'status' => 'paid',
            'source' => 'main_pos',
            'subtotal' => '0',
            'discount_total' => '0',
            'tax_total' => '0',
            'grand_total' => '0',
            'opened_at' => now()->subHour(),
            'closed_at' => now()->subHour(),
            'client_event_id' => 'evt_'.Str::random(16),
            'created_at' => now(),
            'updated_at' => now(),
        ], $order));

        $ids = [];
        foreach ($lines as $line) {
            [$product, $qty, $unit, $total] = $line;
            $ids[] = DB::table('pos_order_items')->insertGetId(array_merge([
                'order_id' => $orderId,
                'product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'qty' => $qty,
                'unit_price_snapshot' => $unit,
                'line_discount' => '0',
                'line_total' => $total,
                'status' => 'served',
                'created_at' => now(),
                'updated_at' => now(),
            ], $line[4] ?? []));
        }

        return [$orderId, $ids];
    }
}
