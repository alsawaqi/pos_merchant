// LAUNCH-P5 B1 — the staff permissions page (work order LAUNCH-P5, Part B):
//   a 5 × 19 tick list with the largest manual discount % per position,
//   plain names and a help line per action in English AND Arabic; it replaces
//   Settings → Order cancellation (the old link redirects); the matrix is gated
//   by staff.permissions.manage while the void/comp reasons keep orders.cancel.
// Run: node --test tests/frontend/launch-p5-permissions.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertBilingual, assertKeysExist, en, exists, get, lib, read, sfc } from './launch-p4-support.mjs';

const fixture = JSON.parse(read('tests/Fixtures/launch-p5/position_permissions_defaults.json'));

test('B1 the four P5 portal permissions are mirrored in the SPA', () => {
    const perms = read('resources/js/lib/permissions.ts');
    assert.match(perms, /StaffPermissionsManage: 'staff\.permissions\.manage',/);
    assert.match(perms, /PosStaffResetPin: 'pos_staff\.reset_pin',/);
    assert.match(perms, /PosStaffChangePosition: 'pos_staff\.change_position',/);
    assert.match(perms, /StaffAttendanceManage: 'staff\.attendance\.manage',/);
});

test('B1 the editor sends only the changed cells and refuses a matrix with no approver or a bad limit', () => {
    const { diffMatrix, approverPositions, validLimit, actionKey, isAlwaysOn, cloneMatrix } = lib('staffPermissions');
    const saved = {
        cashier: { actions: { comp: false, 'discount.manual': true }, discount_max_percent: 10 },
        manager: { actions: { comp: true, 'approvals.give': true }, discount_max_percent: 100 },
    };
    const draft = cloneMatrix(saved);
    assert.deepEqual(JSON.parse(JSON.stringify(diffMatrix(saved, draft))), {});
    draft.cashier.actions.comp = true;
    draft.cashier.discount_max_percent = 15;
    assert.deepEqual(JSON.parse(JSON.stringify(diffMatrix(saved, draft))), { cashier: { actions: { comp: true }, discount_max_percent: 15 } });
    assert.equal(saved.cashier.actions.comp, false, 'the saved matrix is never mutated');

    assert.deepEqual([...approverPositions(saved)], ['manager']);
    draft.manager.actions['approvals.give'] = false;
    assert.deepEqual([...approverPositions(draft)], []);

    assert.equal(validLimit(0), true);
    assert.equal(validLimit(100), true);
    assert.equal(validLimit(101), false);
    assert.equal(validLimit(12.5), false);
    assert.equal(validLimit(Number.NaN), false);
    assert.equal(actionKey('order.void_unpaid'), 'order_void_unpaid');
    assert.equal(isAlwaysOn({ kitchen: ['kitchen.screen'] }, 'kitchen', 'kitchen.screen'), true);
    assert.equal(isAlwaysOn({ kitchen: ['kitchen.screen'] }, 'manager', 'kitchen.screen'), false);
});

test('B1 the page shows the matrix behind staff.permissions.manage and keeps the reasons behind orders.cancel', () => {
    assert.equal(exists('resources/js/Pages/Merchant/Settings/OrderCancellation.vue'), false);
    const { script, template } = sfc('resources/js/Pages/Merchant/Settings/StaffPermissions.vue');
    assert.match(script, /canManagePermissions = computed\(\(\) => can\(MerchantPermission\.StaffPermissionsManage\)\)/);
    assert.match(script, /canManage = computed\(\(\) => can\(MerchantPermission\.OrdersCancel\)\)/);
    assert.match(script, /updateStaffPermissions\(changes\.value\)/);
    assert.match(template, /data-test="staff-permissions-matrix"/);
    assert.match(template, /:data-test="`cell-\$\{position\}-\$\{action\}`"/);
    assert.match(template, /:data-test="`limit-\$\{position\}`"/);
    assert.match(template, /:disabled="locked\(position, action\)"/);
    assert.match(template, /data-test="restore-defaults"/);
    assert.match(template, /data-test="no-approver"/);
    assert.match(template, /v-if="canManage" class="mt-8 space-y-8"/, 'void + comp reasons still on the page');
    assertKeysExist(script + template, 'staff permissions page');

    const api = read('resources/js/lib/api/staffPermissions.ts');
    assert.match(api, /'\/api\/settings\/staff-permissions'/);
    assert.match(api, /\{ permissions: changes \}/);
    for (const old of ['orderCancellation', 'managerApproval', 'reportsPositions', 'kitchenPositions']) {
        assert.equal(exists(`resources/js/lib/api/${old}.ts`), false, `${old}.ts is retired`);
    }
});

test('B1 it replaces Settings → Order cancellation: the old link redirects and the sidebar shows the new entry', () => {
    const router = read('resources/js/router.ts');
    assert.match(router, /path: '\/settings\/staff-permissions',\s*name: 'merchant\.settings\.staff-permissions',\s*component: SettingsStaffPermissions/);
    assert.match(router, /\{ path: '\/settings\/order-cancellation', redirect: '\/settings\/staff-permissions' \}/);
    const layout = read('resources/js/Layouts/MerchantLayout.vue');
    assert.match(layout, /key: 'staff_permissions', to: '\/settings\/staff-permissions', icon: Settings, permission: \[MerchantPermission\.StaffPermissionsManage, MerchantPermission\.OrdersCancel\]/);
    assert.doesNotMatch(layout, /key: 'order_cancellation'/);
    assert.match(layout, /Array\.isArray\(item\.permission\) \? item\.permission\.some\(\(p\) => can\(p\)\)/);
    assert.ok(get(en, 'nav.staff_permissions') && get(ar, 'nav.staff_permissions'));
});

test('B1 every action of the shared fixture has a plain name and a help line in English and Arabic', () => {
    assertBilingual('settings.staff_permissions');
    assert.equal(fixture.actions.length, 19);
    for (const action of fixture.actions) {
        const key = action.replace(/\./g, '_');
        for (const field of ['label', 'help']) {
            assert.ok(String(get(en, `settings.staff_permissions.actions.${key}.${field}`) ?? '').trim(), `en ${action} ${field}`);
            assert.ok(String(get(ar, `settings.staff_permissions.actions.${key}.${field}`) ?? '').trim(), `ar ${action} ${field}`);
        }
    }
    for (const position of fixture.positions) {
        assert.ok(get(en, `pos_staff.positions.${position}`) && get(ar, `pos_staff.positions.${position}`), position);
    }
});
