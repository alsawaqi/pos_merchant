// LAUNCH costs & allergens, Part A — fix order 1 (LAUNCH-COSTS_A_FIX_ORDER_1.md):
//   K-1 the product page keeps the merchant's own allergen ticks exactly as
//       they are (a tick the recipe also brings today is never dropped), and
//       a price-only save sends no allergen change;
//   K-4 the Costs list shows why an inactive / not-on-sale dish is not
//       counted on the dashboard.
// Run: node --test tests/frontend/launch-costs-fix1.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertKeysExist, en, get, lib, sfc } from './launch-p4-support.mjs';

test('K-1 the own ticks are sent as they are, whatever the recipe brings today', () => {
    const { productAllergenPayload, allergenTicksChanged } = lib('allergens');
    // "contains milk" ticked by hand while the cheese in the recipe brings milk too.
    assert.deepEqual(JSON.parse(JSON.stringify(productAllergenPayload(['milk'], ['sesame']))), { contains: ['milk'], may_contain: ['sesame'] });
    assert.deepEqual(JSON.parse(JSON.stringify(productAllergenPayload(['milk', 'gluten', 'milk'], []))), { contains: ['gluten', 'milk'], may_contain: [] });
    // A price-only save: the ticks are the ones loaded → no allergen call.
    const loaded = { contains: ['milk'], may_contain: ['sesame'] };
    assert.equal(allergenTicksChanged(productAllergenPayload(['milk'], ['sesame']), loaded), false);
    assert.equal(allergenTicksChanged(productAllergenPayload(['milk', 'soy'], ['sesame']), loaded), true);
    assert.equal(allergenTicksChanged(productAllergenPayload(['milk'], []), loaded), true);

    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.doesNotMatch(wizard.script, /productAllergenPayload\(derivedAllergens/);
    assert.doesNotMatch(wizard.script, /productAllergenPayload\(\[\]/);
    assert.match(wizard.script, /allergenTicksChanged\(productAllergenPayload\(ownContains\.value, ownMayContain\.value\), allergensBaseline\.value\)/);
    assert.match(wizard.script, /saveProductAllergens\(uuid, productAllergenPayload\(ownContains\.value, ownMayContain\.value\)\)/);
});

test('K-4 the Costs list marks an inactive or not-on-sale dish', () => {
    const page = sfc('resources/js/Pages/Merchant/Costs/Index.vue');
    assert.match(page.template, /data-test="dish-inactive"/);
    assert.match(page.template, /data-test="dish-not-on-sale"/);
    assertKeysExist(page.template, 'Costs');
    assert.equal(get(en, 'costs.dishes.inactive'), 'Inactive');
    assert.equal(get(ar, 'costs.dishes.inactive'), 'غير نشط');
    assert.equal(get(en, 'costs.dishes.not_on_sale'), 'Not on sale today');
    assert.equal(get(ar, 'costs.dishes.not_on_sale'), 'غير معروض للبيع اليوم');
});
