// LAUNCH-P5 B4 — the Hours report (per person and Muscat day, with totals;
//   "no clock-out" rows stand out; corrections need staff.attendance.manage
//   and a reason); every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-hours.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, lib, read, sfc } from './launch-p4-support.mjs';

test('B4 times stay in Muscat-local "Y-m-d H:i" between the report and the edit form', () => {
    const { toInputValue, fromInputValue, timeOf, formatHours, hoursRowClass } = lib('hours');
    assert.equal(toInputValue('2026-10-05 09:00'), '2026-10-05T09:00');
    assert.equal(toInputValue(null), '');
    assert.equal(fromInputValue('2026-10-05T17:15'), '2026-10-05 17:15');
    assert.equal(fromInputValue('2026-10-05T17:15:00'), '2026-10-05 17:15');
    assert.equal(fromInputValue('  '), null);
    assert.equal(timeOf('2026-10-05 09:00'), '09:00');
    assert.equal(formatHours(8.25), '8.25');
    assert.equal(formatHours(null), '—');
    assert.equal(hoursRowClass({ no_clock_out: true, open: false }), 'bg-amber-50');
    assert.equal(hoursRowClass({ no_clock_out: false, open: true }), 'bg-emerald-50/50');
    assert.equal(hoursRowClass({ no_clock_out: false, open: false }), '');
});

test('B4 the Hours page: totals, highlighted missing clock-outs, a person filter, export, and edits behind the permission', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Hours.vue');
    assert.match(script, /canEdit = computed\(\(\) => can\(MerchantPermission\.StaffAttendanceManage\)\)/);
    assert.match(template, /export-key="hours"/);
    assert.match(template, /data-test="filter-staff"/);
    assert.match(template, /data-test="no-clock-out-banner"/);
    assert.match(template, /:data-test="row\.no_clock_out \? 'hours-no-clock-out' : 'hours-row'"/);
    assert.match(template, /v-if="canEdit" class="px-4 py-2 text-end"[\s\S]*?data-test="hours-edit"/);
    assert.match(template, /data-test="edit-reason"/);
    assert.match(script, /canSaveEdit = computed\(\(\) => editForm\.reason\.trim\(\)\.length >= 3/);
    assert.match(script, /updateAttendance\(editTarget\.value\.uuid, \{/);
    assertKeysExist(script + template, 'hours page');

    assert.match(read('resources/js/lib/api/attendance.ts'), /apiPatch<UpdateAttendanceResponse>\(`\/api\/attendance\/\$\{uuid\}`/);
    assert.match(read('resources/js/lib/api/reports.ts'), /export function fetchHoursReport\(filter: HoursReportFilter\)[\s\S]*?reportPath\('hours', filter\)/);
    assert.match(read('resources/js/router.ts'), /\{ path: '\/reports\/hours', name: 'merchant\.reports\.hours', component: ReportsHours/);
    assert.match(read('resources/js/Pages/Merchant/Reports/Index.vue'), /\{ key: 'hours', to: '\/reports\/hours', icon: Timer \}/);
});

test('B4 every Hours text exists in English and Arabic', () => {
    assertBilingual('reports.hours');
    assertBilingual('reports.landing.tile.hours');
});
