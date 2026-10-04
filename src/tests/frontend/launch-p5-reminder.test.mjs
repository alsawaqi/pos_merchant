// LAUNCH-P5 B6 — branch settings: the shift-end reminder time ("HH:MM",
//   Muscat; blank = off) on the branch page, editable with branches.update;
//   the branch staff list says when someone's home branch is elsewhere (B2);
//   every new text in English AND Arabic.
// Run: node --test tests/frontend/launch-p5-reminder.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, ar, en, get, lib, read, sfc } from './launch-p4-support.mjs';

test('B6 a blank time means off; only HH:MM is saved', () => {
    const { normalizeReminder } = lib('shiftReminder');
    assert.equal(normalizeReminder(''), null);
    assert.equal(normalizeReminder('   '), null);
    assert.equal(normalizeReminder(null), null);
    assert.equal(normalizeReminder('21:30'), '21:30');
    assert.equal(normalizeReminder('21:30:00'), '21:30');
    assert.equal(normalizeReminder('00:00'), '00:00');
    assert.equal(normalizeReminder('24:00'), undefined);
    assert.equal(normalizeReminder('9:30'), undefined);
    assert.equal(normalizeReminder('21.30'), undefined);
});

test('B6 the branch page shows the reminder and saves it with branches.update', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Branches/Show.vue');
    assert.match(template, /data-test="shift-reminder"/);
    assert.match(template, /:disabled="!canManageBranch \|\| reminderSaving"[\s\S]*?data-test="shift-reminder-time"/);
    assert.match(template, /v-if="canManageBranch"[\s\S]*?data-test="shift-reminder-save"/);
    assert.match(script, /const time = normalizeReminder\(reminderTime\.value\);/);
    assert.match(script, /updateShiftEndReminder\(uuid, time\)/);
    assert.match(template, /data-test="staff-home-elsewhere"/);
    assertKeysExist(script + template, 'branch page');
    const api = read('resources/js/lib/api/branches.ts');
    assert.match(api, /`\/api\/pos\/branches\/\$\{uuid\}\/shift-end-reminder`/);
    assert.match(api, /\{ shift_end_reminder_at: time \}/);
});

test('B6 every reminder text exists in English and Arabic', () => {
    assertBilingual('branches.show.shift_reminder');
    assert.ok(get(en, 'branches.show.home_elsewhere') && get(ar, 'branches.show.home_elsewhere'));
});
