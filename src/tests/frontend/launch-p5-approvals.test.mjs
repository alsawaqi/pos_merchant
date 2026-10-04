// LAUNCH-P5 B3 — the Approvals report (filters: branch, date, action,
//   approver, actor, result; failed / missing / unverifiable stand out;
//   export with the same filters), the Comp report's approver column ("Not
//   verified" instead of crediting the cashier), and Staff Activity "Voids
//   performed"; every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-approvals.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, ar, en, get, lib, read, sfc } from './launch-p4-support.mjs';

const fixture = JSON.parse(read('tests/Fixtures/launch-p5/position_permissions_defaults.json'));

test('B3 failed, missing and unverifiable are the highlighted results; every action has a name', () => {
    const { isProblem, resultBadgeClass, actionLabelKey, ALL_RESULTS, ONLINE_ACTIONS } = lib('approvals');
    const { TICK_LIST_ACTIONS } = lib('staffPermissions');
    assert.deepEqual([...TICK_LIST_ACTIONS], fixture.actions, 'the SPA list matches the shared fixture');

    for (const r of ['failed', 'missing', 'unverifiable']) {
        assert.equal(isProblem(r), true, r);
        assert.match(resultBadgeClass(r), /rose/);
    }
    for (const r of ['verified', 'position_ok', 'legacy']) {
        assert.equal(isProblem(r), false, r);
    }
    assert.match(resultBadgeClass('verified'), /emerald/);

    for (const action of [...fixture.actions, ...ONLINE_ACTIONS]) {
        const key = actionLabelKey(action, TICK_LIST_ACTIONS);
        assert.ok(key, action);
        assert.ok(String(get(en, key) ?? '').trim(), `en ${key}`);
        assert.ok(String(get(ar, key) ?? '').trim(), `ar ${key}`);
    }
    assert.equal(actionLabelKey('something.new', TICK_LIST_ACTIONS), null);
    for (const r of ALL_RESULTS) {
        assert.ok(get(en, `reports.approvals.results.${r}`) && get(ar, `reports.approvals.results.${r}`), r);
    }
});

test('B3 the report URL carries its own filters, for the page and the export', () => {
    const api = read('resources/js/lib/api/reports.ts');
    assert.match(api, /const EXTRA_FILTER_KEYS = \['action', 'approver_staff_id', 'actor_staff_id', 'result', 'staff_id', 'page', 'per_page'\] as const;/);
    assert.match(api, /for \(const key of EXTRA_FILTER_KEYS\)/);
    assert.match(api, /export function fetchApprovalsReport\(filter: ApprovalsReportFilter\)[\s\S]*?reportPath\('approvals', filter\)/);
    assert.match(api, /result\?: ApprovalResult \| 'problems' \| null;/);
});

test('B3 the Approvals page: four filters, red problem rows, a problems banner, paging and export', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Approvals.vue');
    assert.match(template, /export-key="approvals"/);
    assert.match(template, /:model-value="exportFilter"/);
    for (const f of ['filter-action', 'filter-approver', 'filter-actor', 'filter-result', 'problems-banner']) {
        assert.match(template, new RegExp(`data-test="${f}"`), f);
    }
    assert.match(template, /<option value="problems">/);
    assert.match(template, /:class="row\.problem \? 'bg-rose-50\/70' : ''"/);
    assert.match(template, /:data-test="row\.problem \? 'approval-problem' : 'approval-row'"/);
    assert.match(script, /fetchApprovalsReport\(withExtras\(base\)\)/);
    assert.match(script, /const exportFilter = computed<ApprovalsReportFilter>\(\(\) => withExtras\(filter\.value\)\)/);
    assertKeysExist(script + template, 'approvals page');

    const router = read('resources/js/router.ts');
    assert.match(router, /\{ path: '\/reports\/approvals', name: 'merchant\.reports\.approvals', component: ReportsApprovals/);
    const index = read('resources/js/Pages/Merchant/Reports/Index.vue');
    assert.match(index, /\{ key: 'approvals', to: '\/reports\/approvals', icon: ShieldCheck \}/);
});

test('B3 the Comp report shows "Not verified" instead of crediting the cashier', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Comps.vue');
    assert.match(template, /data-test="comps-by-approver"/);
    assert.match(template, /r\.verified \? r\.staff_name : t\('reports\.comps\.not_verified'\)/);
    assert.match(template, /v-if="r\.approval_verified"/);
    assert.match(script, /r\.verified \? \(r\.staff_name \?\? '—'\) : t\('reports\.comps\.not_verified'\)/);
    assertKeysExist(script + template, 'comps page');
    assert.match(read('resources/js/lib/api/reports.ts'), /by_staff: \{ staff_id: number \| null; staff_name: string \| null; verified: boolean; value: string; comp_count: number \}\[\];/);
});

test('B3 every new report text exists in English and Arabic', () => {
    assertBilingual('reports.approvals');
    assertBilingual('reports.landing.tile.approvals');
    for (const key of ['reports.comps.not_verified', 'reports.comps.approver', 'reports.comps.approver_hint', 'reports.staff_activity.columns.voids']) {
        assert.ok(get(en, key) && get(ar, key), key);
    }
    assert.equal(get(en, 'reports.staff_activity.columns.voids'), 'Voids performed');
});
