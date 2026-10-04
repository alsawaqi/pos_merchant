// LAUNCH-P5 B2 — the staff form (work order LAUNCH-P5, Part B):
//   a branch multi-select (home branch first, always included, within the
//   user's own branches; others stay locked), Reset PIN behind
//   pos_staff.reset_pin and the position behind pos_staff.change_position (M6);
//   every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-staff-form.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, en, ar, get, lib, read, sfc } from './launch-p4-support.mjs';

test('B2 the branch payload puts the home branch first and keeps it whatever is ticked', () => {
    const { branchIdsPayload, lockedBranches, tickedOthers } = lib('staffBranches');
    assert.deepEqual([...branchIdsPayload(3, [5, 3, 4, 5])], [3, 4, 5]);
    assert.deepEqual([...branchIdsPayload(7, [])], [7]);

    const staffBranches = [{ id: 1, name: 'Muttrah', home: true }, { id: 2, name: 'Seeb', home: false }, { id: 9, name: 'Sohar', home: false }];
    // The user sees branches 1 and 2 only: 9 is shown locked, 2 is ticked.
    assert.deepEqual(lockedBranches(staffBranches, [1, 2]).map((b) => b.id), [9]);
    assert.deepEqual([...tickedOthers(staffBranches, 1, [1, 2])], [2]);
    assert.deepEqual([...tickedOthers(undefined, 1, [1, 2])], []);
});

test('B2 the staff page sends branch_ids, shows locked branches, and gates Reset PIN and the position', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/PosStaff/Index.vue');
    assert.match(script, /canResetPin = computed\(\(\) => can\(MerchantPermission\.PosStaffResetPin\)\)/);
    assert.match(script, /canChangePosition = computed\(\(\) => can\(MerchantPermission\.PosStaffChangePosition\)\)/);
    assert.match(script, /branch_ids: branchIdsPayload\(createForm\.branch_id, createExtraBranches\.value\)/);
    assert.match(script, /branch_ids: branchIdsPayload\(editForm\.branch_id, editExtraBranches\.value\)/);
    assert.match(script, /\.\.\.\(canChangePosition\.value \? \{ position: editForm\.position \} : \{\}\)/);
    assert.match(template, /v-if="canResetPin"[\s\S]*?data-test="reset-pin"/);
    assert.doesNotMatch(template, /v-if="can\(MerchantPermission\.PosStaffUpdate\)"[^>]*\n[^>]*\n[^>]*\n[^>]*@click="onResetPin/);
    assert.match(template, /data-test="create-other-branches"/);
    assert.match(template, /data-test="edit-other-branches"/);
    assert.match(template, /data-test="locked-branch"/);
    assert.match(template, /:disabled="!canChangePosition" data-test="edit-position"/);
    assert.match(template, /data-test="staff-other-branches"/);
    assertKeysExist(script + template, 'staff page');

    const api = read('resources/js/lib/api/posStaff.ts');
    assert.equal((api.match(/branch_ids\?: number\[\];/g) ?? []).length, 2, 'create + update payloads');
    assert.match(api, /branches\?: \{ id: number; name: string \| null; home: boolean \}\[\];/);
});

test('B2 every new staff-form text exists in English and Arabic', () => {
    assertBilingual('pos_staff.branches');
    assert.ok(get(en, 'pos_staff.position_locked') && get(ar, 'pos_staff.position_locked'));
});
