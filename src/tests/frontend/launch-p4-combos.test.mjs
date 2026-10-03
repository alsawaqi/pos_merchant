// LAUNCH-P4 B2 — the combos editor (work order LAUNCH-P4, Part B):
//   create or edit a combo: name, photo, category, price per channel, slots,
//   and options with extra prices and defaults; a preview of the price range;
//   combos appear in the catalogue list with a "Combo" badge; every new text
//   exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-combos.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, exists, lib, read, sfc } from './launch-p4-support.mjs';

test('B2 the price range runs from the cheapest to the dearest choice, in baisas', () => {
    assert.ok(exists('resources/js/lib/combo.ts'), 'lib/combo.ts');
    const { comboPriceRange, toBaisas, fromBaisas } = lib('combo');
    assert.equal(toBaisas('1.25'), 1250);
    assert.equal(toBaisas(''), 0);
    assert.equal(fromBaisas(3600), '3.600');
    const range = comboPriceRange('2.500', [
        { min_choices: 1, max_choices: 1, options: [{ product_uuid: 'a', extra_price: '0', is_default: true }, { product_uuid: 'b', extra_price: '0.500', is_default: false }] },
        { min_choices: 0, max_choices: 2, options: [{ product_uuid: 'c', extra_price: '0.300', is_default: false }, { product_uuid: 'd', extra_price: '0.200', is_default: false }] },
    ]);
    assert.deepEqual({ ...range }, { min: '2.500', max: '3.600' });
    // 0.1 + 0.2 never drifts: integer baisas.
    assert.deepEqual({ ...comboPriceRange('0.100', [{ min_choices: 1, max_choices: 1, options: [{ product_uuid: 'x', extra_price: '0.200', is_default: false }] }]) }, { min: '0.300', max: '0.300' });
});

test('B2 slot problems block the save: least/most, items, duplicates, defaults', () => {
    const { slotIssues } = lib('combo');
    const ok = { min_choices: 1, max_choices: 2, options: [{ product_uuid: 'a', extra_price: '0', is_default: true }] };
    assert.deepEqual([...slotIssues(ok)], []);
    assert.deepEqual([...slotIssues({ ...ok, min_choices: 3 })], ['max_below_min']);
    assert.deepEqual([...slotIssues({ ...ok, options: [] })], ['no_options']);
    assert.deepEqual([...slotIssues({ ...ok, options: [{ product_uuid: '', extra_price: '0', is_default: false }] })], ['no_item']);
    assert.ok(slotIssues({ ...ok, options: [ok.options[0], ok.options[0]] }).includes('duplicate_item'));
    assert.ok(slotIssues({ min_choices: 0, max_choices: 1, options: [{ product_uuid: 'a', extra_price: '0', is_default: true }, { product_uuid: 'b', extra_price: '0', is_default: true }] }).includes('too_many_defaults'));
});

test('B2 the combo editor covers name, photo, category, prices per channel, slots, options and a preview', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    for (const hook of ['combo-name', 'combo-photo', 'combo-price', 'combo-slots', 'combo-slot', 'slot-min', 'slot-max', 'combo-option', 'option-item', 'option-extra', 'option-default', 'add-slot', 'combo-preview', 'combo-save']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /v-model="form\.category_id"/);
    assert.match(template, /<ChannelsEditor/);
    assert.match(script, /comboPriceRange\(form\.base_price/);
    // Slots keep their ids across edits.
    assert.match(script, /id: slot\.id,/);
    assert.match(script, /createCombo\(payload\(\)\)/);
    assert.match(script, /updateCombo\(editUuid!, payload\(\)\)/);
    assertKeysExist(template + script, 'ComboEditor');
    const api = read('resources/js/lib/api/catalogue.ts');
    assert.match(api, /'\/api\/combos'/);
    assert.match(api, /`\/api\/combos\/\$\{uuid\}`/);
});

test('B2 combos appear in the catalogue list with a Combo badge and open in the combo editor', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(template, /data-test="combo-badge"/);
    assert.match(template, /prod\.product_type === 'combo'/);
    assert.match(template, /`\/catalogue\/combos\/\$\{prod\.uuid\}\/edit`/);
    assert.match(template, /data-test="add-combo"/);
    const router = read('resources/js/router.ts');
    assert.match(router, /path: '\/catalogue\/combos\/new'/);
    assert.match(router, /name: 'merchant\.catalogue\.combo-edit'/);
    // A combo opened through the product wizard URL goes to the combo editor.
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').script, /product_type === 'combo'[\s\S]*?router\.replace\(`\/catalogue\/combos\/\$\{editUuid\}\/edit`\)/);
});

test('B2 every combo text exists in English and Arabic', () => {
    assertBilingual('combos');
});
