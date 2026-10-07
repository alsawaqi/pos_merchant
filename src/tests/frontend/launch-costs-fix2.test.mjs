// LAUNCH costs & allergens, Part A — fix order 2 (LAUNCH-COSTS_A_FIX_ORDER_2.md):
//   K-10 an incomplete cost shows its % as a floor, marked "Cost incomplete";
//   K-12 the Inventory modal never sends a physical item's "may contain".
// Run: node --test tests/frontend/launch-costs-fix2.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertKeysExist, en, get, lib, sfc } from './launch-p4-support.mjs';

test('K-10 an incomplete cost is shown, marked, never as a plain ok', () => {
    const { hasFoodCostPct, foodCostTone } = lib('foodCost');
    assert.equal(hasFoodCostPct({ status: 'ok' }), true);
    assert.equal(hasFoodCostPct({ status: 'incomplete' }), true);
    assert.equal(hasFoodCostPct({ status: 'no_recipe' }), false);
    assert.equal(foodCostTone({ status: 'incomplete', over_target: true }), 'over');
    assert.equal(get(en, 'costs.food_cost.incomplete'), 'Cost incomplete');
    assert.equal(get(ar, 'costs.food_cost.incomplete'), 'التكلفة غير مكتملة');
    for (const [path, hook] of [
        ['resources/js/Pages/Merchant/Catalogue/ProductWizard.vue', 'product-cost-incomplete'],
        ['resources/js/Pages/Merchant/Catalogue/Index.vue', 'product-cost-incomplete'],
        ['resources/js/Pages/Merchant/Costs/Index.vue', 'dish-incomplete'],
    ]) {
        const page = sfc(path);
        assert.match(page.template, new RegExp(`data-test="${hook}"`), path);
        assertKeysExist(page.template, path);
    }
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue').template, /hasFoodCostPct\(saved\.food_cost\)/);
});

test('K-12 the Inventory modal saves a physical item\'s "contains" only', () => {
    const { script } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.doesNotMatch(script, /may_contain: \[\]/);
    assert.match(script, /saveProductAllergens\(created\.data\.uuid, \{ contains: physicalAllergens\.value \}\)/);
    assert.match(script, /saveProductAllergens\(physicalItemModalTarget\.value\.uuid, \{ contains: physicalAllergens\.value \}\)/);
});
