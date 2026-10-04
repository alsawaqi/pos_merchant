// LAUNCH-P5 Part B follow-up 1: hiring into a position that can approve is
//   greyed out without "Change a staff member's position" (the server
//   refuses it too); the Approvals report shows Muscat days and local times
//   with a per-day summary; every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-followup1.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertKeysExist, ar, en, get, read, sfc } from './launch-p4-support.mjs';

test('FU1 the hire form greys out approver positions without pos_staff.change_position', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/PosStaff/Index.vue');
    assert.match(script, /approverPositions\.value = response\.meta\?\.approver_positions \?\? \[\];/);
    assert.match(script, /return !canChangePosition\.value && approverPositions\.value\.includes\(position\);/);
    assert.match(template, /data-test="create-position"[\s\S]*?:disabled="hireLocked\(opt\.value\)"/);
    assert.match(template, /data-test="approver-hire-hint"/);
    assertKeysExist(script + template, 'staff page');
    assert.match(read('resources/js/lib/api/posStaff.ts'), /meta\?: \{ approver_positions\?: string\[\] \};/);
    const dialog = sfc('resources/js/Pages/Merchant/Branches/components/BranchAssignStaffDialog.vue');
    assert.match(dialog.script, /e\.status === 403 \? t\('pos_staff\.approver_hire_locked'\)/);
});

test('FU1 the Approvals page shows Muscat-local times and a per-day summary', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Reports/Approvals.vue');
    assert.match(script, /return row\.approved_local \?\? row\.recorded_local \?\? '—';/);
    assert.doesNotMatch(script, /toLocaleString/);
    assert.match(template, /data-test="approvals-by-day"/);
    assertKeysExist(script + template, 'approvals page');
    assert.match(read('resources/js/lib/api/reports.ts'), /by_day: \{ day: string; total: number; problems: number \}\[\];/);
});

test('FU1 every new text exists in English and Arabic', () => {
    for (const key of ['pos_staff.approver_hire_locked', 'reports.approvals.timezone_note', 'reports.approvals.by_day', 'reports.approvals.columns.day']) {
        assert.ok(String(get(en, key) ?? '').trim() && String(get(ar, key) ?? '').trim(), key);
    }
});
