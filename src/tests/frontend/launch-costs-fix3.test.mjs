// LAUNCH costs & allergens, Part A — fix order 3 (LAUNCH-COSTS_A_FIX_ORDER_3.md):
//   K-14 the dashboard card says how many costed dishes miss a cost;
//   K-15 the Recipe & Cost report shows "Recipe cost" next to Actual and
//        Change, and its hint says what is compared with what (EN + AR).
// Run: node --test tests/frontend/launch-costs-fix3.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertKeysExist, en, get, sfc } from './launch-p4-support.mjs';

test('K-15 the report shows the recipe part next to Actual and Change, and the hint explains it', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Reports/RecipeCost.vue');
    assert.match(template, /data-test="recipe-cost-recipe-part">\{\{ r\.recipe_cost \?\? r\.theoretical_cost \}\}/);
    const headers = [...template.matchAll(/<th[^>]*>\{\{ t\('([a-z_.]+)'\) \}\}<\/th>/g)].map((m) => m[1]);
    const at = headers.indexOf('costs.report.recipe_cost');
    assert.ok(at > 0, 'a "Recipe cost" column');
    assert.equal(headers[at + 1], 'reports.recipe_cost.columns.actual_cost_per_unit');
    assert.equal(headers[at + 2], 'reports.recipe_cost.columns.cost_change_per_unit');
    assertKeysExist(template, 'RecipeCost');
    assert.equal(get(en, 'costs.report.recipe_cost'), 'Recipe cost');
    assert.equal(get(ar, 'costs.report.recipe_cost'), 'تكلفة الوصفة');
    assert.match(get(en, 'reports.recipe_cost.filters_hint'), /Recipe cost is the recipe part alone/);
    assert.match(get(en, 'reports.recipe_cost.filters_hint'), /food components/);
    assert.match(get(ar, 'reports.recipe_cost.filters_hint'), /تكلفة الوصفة هي جزء الوصفة وحده/);
});

test('K-14 the dashboard card names the costed dishes that miss a cost', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Dashboard.vue');
    assert.match(template, /data-test="dishes-incomplete"/);
    assertKeysExist(template, 'Dashboard');
    assert.equal(get(en, 'costs.dashboard.dishes_incomplete'), '{count} with a cost missing');
    assert.equal(get(ar, 'costs.dashboard.dishes_incomplete'), '{count} بتكلفة ناقصة');
});
