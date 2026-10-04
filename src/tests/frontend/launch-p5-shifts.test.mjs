// LAUNCH-P5 B5 — the Shift report shows who closed the drawer, pay-outs,
//   late cash sales, "needs review" and the corrected expected cash /
//   variance; re-open follows the server's Muscat-day rule (row.reopenable),
//   not the browser's own date; every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-shifts.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertKeysExist, ar, en, get, read, sfc } from './launch-p4-support.mjs';

test('B5 the Shift report shows the P5 close data and flags shifts that need review', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Shifts.vue');
    for (const col of ['closed_by', 'payouts', 'late_sales', 'corrected_expected', 'corrected_variance']) {
        assert.match(template, new RegExp(`t\\('reports\\.shifts\\.columns\\.${col}'\\)`), col);
    }
    assert.match(template, /\{\{ row\.closed_by_name \?\? '—' \}\}/);
    assert.match(template, /\{\{ row\.corrected_expected_cash \?\? '—' \}\}/);
    assert.match(template, /:class="row\.needs_review \? 'bg-amber-50' : ''"/);
    assert.match(template, /data-test="needs-review-banner"/);
    assert.match(template, /payload\.summary\.needs_review_count/);
    assertKeysExist(script + template, 'shifts page');
});

test('B5 re-open uses the server\'s Muscat-day rule, not the browser date', () => {
    const { script } = sfc('resources/js/Pages/Merchant/Reports/Shifts.vue');
    assert.match(script, /return row\.status === 'closed' && row\.reopenable === true;/);
    assert.doesNotMatch(script, /new Date\(\)/);
    const api = read('resources/js/lib/api/reports.ts');
    assert.match(api, /reopenable: boolean;/);
    assert.match(api, /corrected_variance: string \| null;/);
});

test('B5 every new Shift report text exists in English and Arabic', () => {
    for (const key of [
        'reports.shifts.columns.closed_by', 'reports.shifts.columns.payouts', 'reports.shifts.columns.late_sales',
        'reports.shifts.columns.corrected_expected', 'reports.shifts.columns.corrected_variance', 'reports.shifts.needs_review',
        'reports.shifts.needs_review_hint', 'reports.shifts.headline_labels.needs_review', 'reports.shifts.headline_labels.total_payouts',
        'reports.shifts.headline_labels.total_late_sales', 'reports.shifts.headline_labels.total_corrected_variance',
    ]) {
        assert.ok(String(get(en, key) ?? '').trim() && String(get(ar, key) ?? '').trim(), key);
    }
});
