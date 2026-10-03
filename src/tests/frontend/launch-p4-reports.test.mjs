// LAUNCH-P4 B8 — reports with combos and VAT (work order LAUNCH-P4, Part B):
//   Product performance gets an "Inside combos" column and a Combo badge; the
//   Sales report says sales are excluding VAT (and how many orders had it
//   inside); the order drawer lists the items of a combo and marks VAT
//   included in the prices; every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-reports.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, read, sfc } from './launch-p4-support.mjs';

test('B8 Product performance shows "Inside combos" and the Combo badge', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Reports/ProductPerformance.vue');
    assert.match(template, /t\('report_combos\.inside_combos'\)/);
    assert.match(template, /data-test="inside-combos"/);
    assert.match(template, /r\.inside_combos_qty/);
    assert.match(template, /data-test="report-combo-badge"/);
    assert.match(read('resources/js/lib/api/reports.ts'), /inside_combos_qty\?: string;/);
});

test('B8 the Sales report says sales are excluding VAT', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Reports/Sales.vue');
    assert.match(template, /data-test="vat-excluded-hint"/);
    assert.match(template, /payload\.headline\.vat_inclusive_orders/);
});

test('B8 the order drawer lists the items inside a combo and marks VAT included in prices', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Orders/components/OrderDetailDrawer.vue');
    assert.match(template, /data-test="combo-components"/);
    assert.match(template, /v-for="\(c, i\) in item\.components"/);
    assert.match(template, /v-if="detail\.order\.totals\.prices_include_tax"[^>]*data-test="tax-included"/);
});

test('B8 every new report text exists in English and Arabic', () => {
    assertBilingual('report_combos');
    assertBilingual('report_vat');
});
