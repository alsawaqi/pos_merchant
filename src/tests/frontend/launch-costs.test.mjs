// LAUNCH costs & allergens add-on, Part A (LAUNCH-COSTS_ALLERGENS_WORK_ORDER.md):
//   1. supplier price history (the ingredient page's "Price history" tab) and
//      the price-change alert (purchase confirmation, a list with the dishes
//      affected and "mark as seen", a dashboard card, the threshold setting);
//   2. a target food cost % (company + per product), the % on the product
//      list and page with a red flag, "Target" / "Over by" on the Recipe &
//      Cost report, a "Dishes over target" card linked to the list;
//   3. allergens ticked on ingredients, prep items and physical items; a
//      dish's worked out (locked) plus "contains" / "may contain" by hand.
//   Every new text in English and Arabic.
// Run: node --test tests/frontend/launch-costs.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertBilingual, assertKeysExist, en, exists, get, lib, read, sfc } from './launch-p4-support.mjs';

const CODES = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk', 'tree_nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

test('the 14 allergens in the server order; ticks keep that order and never drop a worked-out one', () => {
    const { ALLERGEN_CODES, normaliseAllergens, toggleAllergen, productAllergenPayload, sameAllergens } = lib('allergens');
    assert.deepEqual([...ALLERGEN_CODES], CODES);
    assert.deepEqual([...normaliseAllergens(['milk', 'gluten', 'nuts', 'milk'])], ['gluten', 'milk']);
    assert.deepEqual([...toggleAllergen(['milk'], 'gluten')], ['gluten', 'milk']);
    assert.deepEqual([...toggleAllergen(['gluten', 'milk'], 'milk')], ['gluten']);
    // A locked (worked-out) allergen cannot be unticked.
    assert.deepEqual([...toggleAllergen(['gluten'], 'gluten', ['gluten'])], ['gluten']);
    // Fix order 1 (K-1) — the product page sends its own ticks exactly as they are.
    const payload = productAllergenPayload(['soy', 'gluten'], ['gluten', 'soy', 'sesame']);
    assert.deepEqual(JSON.parse(JSON.stringify(payload)), { contains: ['gluten', 'soy'], may_contain: ['gluten', 'soy', 'sesame'] });
    assert.ok(sameAllergens(['milk', 'gluten'], ['gluten', 'milk']));
    assert.ok(!sameAllergens(['milk'], ['milk', 'soy']));
});

test('food cost and price changes are shown, never worked out, in the portal', () => {
    const { pctText, changeText, foodCostTone, baisasText, targetProblem, targetPayload, friendlyUnitCost } = lib('foodCost');
    assert.equal(pctText(32.5), '32.5%');
    assert.equal(pctText(30), '30.0%');
    assert.equal(pctText(null), null);
    assert.equal(changeText(11.1), '+11.1%');
    assert.equal(changeText(-16.7), '−16.7%');
    assert.equal(foodCostTone({ status: 'ok', over_target: true }), 'over');
    assert.equal(foodCostTone({ status: 'ok', over_target: false }), 'ok');
    assert.equal(foodCostTone({ status: 'no_recipe', over_target: false }), 'none');
    assert.equal(foodCostTone(null), 'none');
    assert.equal(baisasText(650), '0.650');
    assert.equal(baisasText(3000), '3.000');
    assert.equal(baisasText(null), '');
    assert.equal(targetProblem(''), null);
    assert.equal(targetProblem('28.5'), null);
    assert.equal(targetProblem('100'), null);
    assert.equal(targetProblem('0'), 'range');
    assert.equal(targetProblem('101'), 'range');
    assert.equal(targetProblem('2.555'), 'invalid');
    assert.equal(targetPayload(''), null);
    assert.equal(targetPayload(' 35 '), '35');
    assert.deepEqual({ ...friendlyUnitCost('0.000600', 'g') }, { amount: '0.600', unit: 'kg' });
    assert.deepEqual({ ...friendlyUnitCost('0.050000', 'piece') }, { amount: '0.050', unit: 'piece' });
});

test('every new text is in English and Arabic, the allergen names as the devices get them', () => {
    assertBilingual('allergens');
    assertBilingual('costs');
    assert.equal(get(en, 'nav.costs'), 'Costs');
    assert.equal(get(ar, 'nav.costs'), 'التكاليف');
    assert.deepEqual(Object.keys(get(en, 'allergens.codes')), CODES);
    assert.deepEqual(Object.values(get(ar, 'allergens.codes')), [
        'الغلوتين', 'القشريات', 'البيض', 'الأسماك', 'الفول السوداني', 'الصويا', 'الحليب',
        'المكسرات', 'الكرفس', 'الخردل', 'السمسم', 'الكبريتيت', 'الترمس', 'الرخويات',
    ]);
    // The same names the server sends to devices (App\Support\Catalogue\Allergens::NAMES).
    const server = read('app/Support/Catalogue/Allergens.php');
    for (const code of CODES) {
        assert.ok(server.includes(`'${code}' => ['${get(en, `allergens.codes.${code}`)}', '${get(ar, `allergens.codes.${code}`)}']`), code);
    }
});

test('the ingredient page: allergen ticks and a "Price history" tab; physical items and prep items tick too', () => {
    const ticks = sfc('resources/js/Pages/Merchant/Catalogue/AllergenTicks.vue');
    assert.match(ticks.template, /:data-test="`allergen-tick-\$\{code\}`"/);
    assert.match(ticks.script, /toggleAllergen\(props\.modelValue, code, props\.locked\)/);
    assertKeysExist(ticks.template + ticks.script, 'AllergenTicks');
    const chips = sfc('resources/js/Pages/Merchant/Catalogue/AllergenChips.vue');
    assertKeysExist(chips.template + chips.script, 'AllergenChips');

    const inventory = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    for (const hook of ['ing-tabs', 'ing-tab-details', 'ing-tab-history', 'ingredient-allergens', 'physical-allergens']) {
        assert.match(inventory.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(inventory.template, /<IngredientPriceHistory v-if="ingTab === 'history' && ingModalTarget" :ingredient-uuid="ingModalTarget\.uuid" \/>/);
    assert.match(inventory.script, /saveIngredientAllergens\(saved\.uuid, ingAllergens\.value\)/);
    assert.match(inventory.script, /saveProductAllergens\(physicalItemModalTarget\.value\.uuid, \{ contains: physicalAllergens\.value \}\)/);
    assertKeysExist(inventory.template, 'Inventory');

    const history = sfc('resources/js/Pages/Merchant/Inventory/IngredientPriceHistory.vue');
    for (const hook of ['price-history', 'price-history-empty', 'price-history-row', 'price-history-alert']) {
        assert.match(history.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(history.template + history.script, 'IngredientPriceHistory');

    const prep = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    assert.match(prep.template, /data-test="prep-allergens"/);
    assert.match(prep.template, /data-test="prep-allergens-all"/);
    assert.match(prep.script, /saveIngredientAllergens\(saved\.data\.uuid, allergens\.value\)/);
    assertKeysExist(prep.template, 'PrepItemEditor');
});

test('the product page: its target, its food cost and its allergens (worked out locked, contains, may contain)', () => {
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    for (const hook of ['product-food-cost', 'product-target-food-cost', 'product-food-cost-now', 'product-over-target', 'product-allergens', 'product-allergens-derived']) {
        assert.match(wizard.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(wizard.template, /<AllergenTicks v-model="ownContains" :locked="derivedAllergens"/);
    assert.match(wizard.template, /<AllergenTicks v-model="ownMayContain" :hidden="\[\.\.\.derivedAllergens, \.\.\.ownContains\]"/);
    assert.match(wizard.script, /target_food_cost_percent: targetPayload\(form\.target_food_cost_percent\)/);
    assert.match(wizard.script, /saveProductAllergens\(uuid, productAllergenPayload\(ownContains\.value, ownMayContain\.value\)\)/);
    assert.match(wizard.script, /saveProductAllergens\(created\.data\.uuid/);
    assertKeysExist(wizard.template + wizard.script, 'ProductWizard');
    const combo = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    for (const hook of ['combo-target-food-cost', 'combo-food-cost', 'combo-allergens']) {
        assert.match(combo.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(combo.script, /target_food_cost_percent: targetPayload\(form\.target_food_cost_percent\)/);
    assertKeysExist(combo.template + combo.script, 'ComboEditor');
});

test('the product list shows the % with a red flag and filters "over target"; the report gets Target and Over by', () => {
    const list = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    for (const hook of ['food-cost-over-filter', 'product-food-cost-cell', 'product-over-target-flag']) {
        assert.match(list.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(list.script, /String\(route\.query\.food_cost \?\? ''\) === 'over'/);
    assert.match(list.script, /food_cost_over: canSeeCosts\.value && foodCostOverOnly\.value/);
    assert.match(read('resources/js/lib/api/catalogue.ts'), /food_cost: params\.food_cost_over \? 'over' : undefined/);
    assertKeysExist(list.template, 'Catalogue');
    const report = sfc('resources/js/Pages/Merchant/Reports/RecipeCost.vue');
    for (const hook of ['recipe-cost-food-cost', 'recipe-cost-target', 'recipe-cost-over-by']) {
        assert.match(report.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(report.template, 'RecipeCost');
});

test('the dashboard cards, the Costs page (alerts, dishes, settings) and the purchase confirmation', () => {
    const dashboard = sfc('resources/js/Pages/Merchant/Dashboard.vue');
    assert.match(dashboard.template, /data-test="price-alerts-card"/);
    assert.match(dashboard.template, /to="\/costs\?tab=alerts"/);
    assert.match(dashboard.template, /data-test="dishes-over-target-card"/);
    assert.match(dashboard.template, /to="\/costs\?tab=dishes"/);
    assertKeysExist(dashboard.template, 'Dashboard');

    assert.ok(exists('resources/js/Pages/Merchant/Costs/Index.vue'));
    const page = sfc('resources/js/Pages/Merchant/Costs/Index.vue');
    for (const hook of ['costs-tabs', 'price-alerts', 'price-alerts-show-seen', 'price-alerts-empty', 'price-alert', 'price-alert-change', 'price-alert-dishes',
        'price-alert-dish', 'price-alert-crossed', 'price-alert-seen', 'price-alert-mark-seen', 'dishes-over-target', 'dishes-show-all', 'dish-row',
        'costs-settings', 'costs-threshold', 'costs-target', 'costs-settings-save']) {
        assert.match(page.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(page.script, /canMarkSeen = computed\(\(\) => can\(MerchantPermission\.InventoryManage\)\)/);
    assert.match(page.script, /canEditTarget = computed\(\(\) => can\(MerchantPermission\.CatalogueManage\)\)/);
    assertKeysExist(page.template + page.script, 'Costs');
    assert.match(read('resources/js/router.ts'), /path: '\/costs', name: 'merchant\.costs'/);
    assert.match(read('resources/js/Layouts/MerchantLayout.vue'), /\{ key: 'costs', to: '\/costs', icon: LineChart, permission: MerchantPermission\.ReportsView \}/);

    const receipt = sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Show.vue');
    assert.match(receipt.template, /data-test="receipt-price-alerts"/);
    assert.match(receipt.script, /priceAlerts\.value = res\.data\.price_alerts \?\? \[\]/);
    assertKeysExist(receipt.template, 'PurchaseReceiptShow');

    const api = read('resources/js/lib/api/costs.ts');
    for (const path of ["'/api/settings/costs'", '`/api/ingredients/${ingredientUuid}/price-history`', '`/api/price-alerts${qs ? `?${qs}` : \'\'}`',
        '`/api/price-alerts/${lineId}/seen`', "`/api/food-costs${overOnly ? '?over=1' : ''}`", '`/api/products/${productUuid}/allergens`',
        '`/api/ingredients/${ingredientUuid}/allergens`']) {
        assert.ok(api.includes(path), path);
    }
});
