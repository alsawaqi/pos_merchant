// LAUNCH-P4 B2 — the combos editor (work order LAUNCH-P4, Part B):
//   create or edit a combo: name, photo, category, price per channel, its
//   lines (LAUNCH combo add-on: launch-combo.test.mjs); a price preview;
//   combos appear in the catalogue list with a "Combo" badge; every new text
//   exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p4-combos.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, exists, lib, read, sfc } from './launch-p4-support.mjs';

test('B2 the combo editor covers name, photo, category, prices per channel, included items, choices and a preview', () => {
    // LAUNCH combo add-on — the slots became lines (ComboLinesEditor); see launch-combo.test.mjs.
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    for (const hook of ['combo-name', 'combo-photo', 'combo-price', 'combo-preview', 'combo-save']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /v-model="form\.category_id"/);
    assert.match(template, /<ChannelsEditor/);
    assert.match(template, /<ComboLinesEditor v-model="form\.lines"/);
    assert.match(script, /comboPriceRange\(form\.base_price/);
    // Lines keep their ids across edits.
    assert.match(script, /form\.lines = draftsFrom\(combo\.combo\?\.lines \?\? \[\], nextKey\)/);
    assert.match(script, /lines: linesPayload\(form\.lines, items\.value\),/);
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
