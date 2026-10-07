// LAUNCH combo add-on, Part A fix order 1 (LAUNCH-COMBO_A_FIX_ORDER_1.md):
//   C-4  the Meals page shows a clash that arose outside a meal save;
//   C-5  the combo and meal editors load every product (no 500 cap);
//   C-11 the product wizard blocks a "Can be removed" price above 0 before any write;
//   C-12 the combo editor shows "(unavailable)" for an included item or upgrade
//        that can no longer be sold.
// Run: node --test tests/frontend/launch-combo-fix1.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertKeysExist, lib, read, sfc } from './launch-p4-support.mjs';

test('C-5 the combo and meal editors ask for every product', () => {
    const api = read('resources/js/lib/api/catalogue.ts');
    assert.match(api, /'\/api\/products\/addon-link-options\?all=1'/);
    for (const page of ['ComboEditor', 'MealEditor']) {
        assert.match(sfc(`resources/js/Pages/Merchant/Catalogue/${page}.vue`).script, /listAddonLinkOptions\(\{ all: true \}\)/, page);
    }
});

test('C-11 the wizard blocks a "Can be removed" price above 0 before any write', () => {
    const { script } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(script, /removePriceProblem\(tick\.price\) !== null\)\) \{\s*missing\.push\(t\('menu_extras\.removable\.price_above_zero'\)\);/);
});

test('C-12 an included item or upgrade that can no longer be sold shows "(unavailable)"', () => {
    const { cannotBeSold, draftsFrom } = lib('combo');
    const items = [{ uuid: 'a', name: 'A', status: 'active' }, { uuid: 'b', name: 'B', status: 'inactive' }];
    assert.equal(cannotBeSold('a', false, items), false);
    assert.equal(cannotBeSold('b', false, items), true);
    assert.equal(cannotBeSold('gone', false, items), true);
    assert.equal(cannotBeSold('a', true, items), true);
    assert.equal(cannotBeSold('', false, items), false);
    let n = 0;
    const [line] = draftsFrom([{ id: 1, kind: 'fixed', product_uuid: 'gone', product_name: 'Old fries', product_available: false, quantity: 1,
        upgrades: [{ product_uuid: 'x', upgrade_price: '0.800', product_name: 'Old loaded', product_available: false }],
        name: null, name_ar: null, category_id: null, pick_count: null, items: [] }], (p) => `${p}-${++n}`);
    assert.equal(line.label, 'Old fries');
    assert.equal(line.unavailable, true);
    assert.equal(line.upgrades[0].label, 'Old loaded');
    assert.equal(line.upgrades[0].unavailable, true);
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboLinesEditor.vue');
    for (const hook of ['fixed-unavailable', 'upgrade-unavailable']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /\{\{ line\.label \?\? '—' \}\} \(\{\{ t\('combos\.lines\.unavailable'\) \}\}\)/);
    assertKeysExist(template + script, 'ComboLinesEditor');
});

test('C-4 the Meals page shows a clash that arose outside a meal save', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/Meals.vue');
    assert.match(template, /data-test="meal-row-clash"/);
    assert.match(template, /t\('meals\.clash', \{ product: clash\.product, meal: clash\.meal \}\)/);
    assertKeysExist(template + script, 'Meals');
});
