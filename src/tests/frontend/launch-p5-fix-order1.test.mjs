// LAUNCH-P5 fix order 1, Part B: the Approvals page marks a "legacy" row
//   from a device that already runs a P5 build (F2); the Shift report shows
//   late pay-outs and counts them in the corrected figures (F7); every new
//   text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-fix-order1.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertKeysExist, ar, en, get, read, sfc } from './launch-p4-support.mjs';

test('F2 the Approvals page marks legacy rows from P5 devices', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Approvals.vue');
    assert.match(template, /v-if="row\.legacy_from_p5_device"[^>]*data-test="legacy-from-p5"/);
    assertKeysExist(script + template, 'approvals page');
    assert.match(read('resources/js/lib/api/reports.ts'), /legacy_from_p5_device: boolean;/);
});

test('F7 the Shift report shows late pay-outs and counts them as a correction', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Shifts.vue');
    assert.match(script, /return num\(row\.late_sales\) !== 0 \|\| num\(row\.late_payouts\) !== 0;/);
    assert.match(template, /t\('reports\.shifts\.columns\.late_payouts'\)/);
    assert.match(template, /data-test="late-payouts"/);
    assert.match(template, /payload\.summary\.total_late_payouts/);
    assertKeysExist(script + template, 'shifts page');
    const api = read('resources/js/lib/api/reports.ts');
    assert.match(api, /late_payouts: string;/);
    assert.match(api, /total_late_payouts: string;/);
});

test('fix order 1 texts exist in English and Arabic', () => {
    for (const key of ['reports.approvals.legacy_from_p5_device', 'reports.shifts.columns.late_payouts', 'reports.shifts.headline_labels.total_late_payouts']) {
        assert.ok(String(get(en, key) ?? '').trim() && String(get(ar, key) ?? '').trim(), key);
    }
});
