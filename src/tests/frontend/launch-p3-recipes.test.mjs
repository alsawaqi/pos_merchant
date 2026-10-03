// LAUNCH-P3 — merchant portal recipe screens.
//   P3-1 the recipe unit picker keeps the entered unit, offers the piece unit
//        and refuses an amount that rounds to 0 (product recipes, add-on
//        stock usage, prep recipes);
//   P3-2 the editor sends the note and shows the recipe history;
//   P3-3 "Edit recipes": without it the editors are read-only and send nothing;
//   P3-4 prep items: their own pages, a tab, recipe pickers, the waste picker;
//   P3-5 the Recipe & Cost report applies its filters, the placeholder is gone;
//   every new string exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p3-recipes.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

function sfc(path) {
    const parsed = parse(read(path));
    assert.deepEqual(parsed.errors, [], path);
    return { script: parsed.descriptor.scriptSetup?.content ?? '', template: parsed.descriptor.template?.content ?? '' };
}

/** Load lib/recipeUnits.ts as a plain module (exports collected from `export`). */
function recipeUnits() {
    const source = read('resources/js/lib/recipeUnits.ts');
    const { outputText } = ts.transpileModule(source, {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
    });
    const module = { exports: {} };
    runInNewContext(outputText, { module, exports: module.exports, Math, Number, String, Set, parseFloat });
    return module.exports;
}

const saffron = { unit: 'kg', auto_units: [{ name: 'g', factor: '0.001' }], alt_units: [] };
const bread = { unit: 'g', auto_units: [{ name: 'kg', factor: '1000' }], alt_units: [{ name: 'tray', factor: '2500.0000' }], piece_unit_label: 'loaf', units_per_piece: '500.0000' };

test('P3-1 the recipe unit picker offers base, extra units, the metric pair and the piece unit', () => {
    const { recipeUnitOptions, recipeUnitFactor, recipeUnitName, PIECE_UNIT } = recipeUnits();
    const options = recipeUnitOptions(bread);
    assert.deepEqual([...options.map((o) => o.value)], ['', 'tray', 'kg', PIECE_UNIT]);
    assert.equal(options[3].label, 'loaf (500 g)');
    assert.equal(recipeUnitFactor(bread, PIECE_UNIT), 500);
    assert.equal(recipeUnitFactor(bread, 'kg'), 1000);
    assert.equal(recipeUnitName(bread, PIECE_UNIT), 'loaf');
    assert.equal(recipeUnitName(bread, ''), 'g');
    // A base-piece ingredient has no separate piece option.
    assert.deepEqual([...recipeUnitOptions({ unit: 'piece', piece_unit_label: 'egg', units_per_piece: '1' }).map((o) => o.value)], ['']);
});

test('P3-1 an amount that rounds to 0 in the base unit is caught, with the smallest amount that records', () => {
    const { roundsToZero, smallestEntry, toBaseQuantity, hasTooManyDecimals, recipeLineProblem } = recipeUnits();
    assert.equal(roundsToZero(0.001, '0.04'), true);
    assert.equal(roundsToZero(0.001, '0.05'), false);
    assert.equal(toBaseQuantity(0.001, '0.05'), 0.0001);
    assert.equal(toBaseQuantity(0.001, '5'), 0.005);
    assert.equal(smallestEntry(0.001), '0.1');
    assert.equal(hasTooManyDecimals('1.23456'), true);
    assert.equal(hasTooManyDecimals('1.2340'), false);
    const problem = recipeLineProblem(saffron, 'g', '0.04');
    assert.equal(problem.key, 'recipe_units.too_small');
    assert.equal(problem.params.amount, '0.04 g');
    assert.equal(problem.params.minimum, '0.1 g');
    assert.equal(recipeLineProblem(saffron, 'g', '5'), null);
    // More than 1% lost to the base unit's 4 decimals is refused too (0.05 g → 0.1 g).
    const { roundsInaccurately } = recipeUnits();
    assert.equal(roundsInaccurately(0.001, '0.05'), true);
    assert.equal(roundsInaccurately(0.001, '0.15'), true);
    assert.equal(roundsInaccurately(0.001, '0.1'), false);
    assert.equal(roundsInaccurately(236.5882, '0.333'), false);
    const imprecise = recipeLineProblem(saffron, 'g', '0.15');
    assert.equal(imprecise.key, 'recipe_units.too_imprecise');
    assert.equal(imprecise.params.stored, '0.0002 kg');
    assert.equal(imprecise.params.step, '0.1 g');
    assert.equal(recipeLineProblem(saffron, '', '1.23456').key, 'recipe_units.too_many_decimals');
});

test('P3-1 a stored line reopens exactly as typed, else in its base unit', () => {
    const { lineEntry } = recipeUnits();
    assert.deepEqual({ ...lineEntry({ quantity: '0.005', entered_unit: 'g', entered_quantity: '5' }, 'kg') }, { quantity: '5', unit: 'g' });
    assert.deepEqual({ ...lineEntry({ quantity: '1000.000', entered_unit: '@piece', entered_quantity: '2' }, 'g') }, { quantity: '2', unit: '@piece' });
    // Typed in the base unit, or before P3 (no entered columns).
    assert.deepEqual({ ...lineEntry({ quantity: '150.000', entered_unit: 'g', entered_quantity: '150' }, 'g') }, { quantity: '150', unit: '' });
    assert.deepEqual({ ...lineEntry({ quantity: '0.0050', entered_unit: null, entered_quantity: null }, 'kg') }, { quantity: '0.005', unit: '' });
});

test('P3-1/P3-2/P3-3 the product editor keeps the entered unit, sends the note, shows history and is read-only without "Edit recipes"', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    // Fix order 1, L8 — the one rule: Edit recipes + catalogue view.
    assert.match(script, /const canEditRecipes = computed\(\(\) => canWriteRecipes\(can\)\)/);
    assert.match(script, /listIngredients\(\{ includePrep: true \}\)/);
    assert.match(script, /\.\.\.lineEntry\(line, line\.ingredient\?\.unit\)/);
    assert.match(script, /updateProductRecipe\(uuid, \{ lines: recipePayload\(\), note: recipeNote \}\)/);
    assert.match(script, /recipe_note: canEditRecipes\.value \? recipeNote : null/);
    assert.match(script, /recipe_lines: canEditRecipes\.value \? recipePayload\(\) : \[\]/);
    assert.match(script, /if \(canEditRecipes\.value\) \{\s*await updateProductRecipe/);
    assert.match(template, /recipeUnitOptions\(ingredientByUuid\(line\.ingredient_uuid\), locale\)/);
    assert.match(template, /data-test="recipe-readonly"/);
    assert.match(template, /v-if="!canEditRecipes"/);
    assert.match(template, /data-test="recipe-note"/);
    assert.match(template, /data-test="recipe-line-problem"/);
    assert.match(template, /<RecipeHistoryPanel v-if="isEdit && hasRecipeStep"/);
    assert.match(template, /step="0\.0001"/);
    // Option stock usage: read-only + never sent without the permission.
    assert.equal((template.match(/:readonly="!canEditRecipes"/g) ?? []).length, 3);
    assert.match(script, /consumption: canEditRecipes\.value \? completeConsumptionLines\(o\.consumption\) : \[\]/);
});

test('P3-1/P3-3/P3-4 the add-on stock-usage editor offers the piece unit and prep items, and can be read-only', () => {
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue');
    assert.match(editor.script, /recipeUnitOptions\(ingredient, locale\.value\)/);
    assert.match(editor.script, /readonly\?: boolean/);
    assert.match(editor.template, /<div v-if="readonly"/);
    assert.match(editor.template, /prep_items\.optgroup/);
    assert.match(editor.template, /data-test="consumption-line-problem"/);
    const catalogue = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(catalogue.script, /\.\.\.\(canEditRecipes\.value \? \{ consumption: completeConsumptionLines\(aoConsumption\.value\) \} : \{\}\)/);
    assert.match(catalogue.script, /lineEntry\(l, l\.ingredient\?\.unit\)/);
    assert.match(catalogue.template, /:readonly="!canEditRecipes"/);
    assert.match(read('resources/js/lib/permissions.ts'), /CatalogueRecipesManage: 'catalogue\.recipes\.manage'/);
});

test('P3-4 prep items have their own editor, an Inventory tab, and are offered for waste only', () => {
    const router = read('resources/js/router.ts');
    assert.match(router, /path: '\/inventory\/prep-items\/new',\s*name: 'merchant\.prep-items\.create'/);
    assert.match(router, /path: '\/inventory\/prep-items\/:uuid',\s*name: 'merchant\.prep-items\.edit'/);
    const editor = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    // LAUNCH item kind, A6: the kind question stores the small unit (g / ml / piece).
    assert.match(editor.script, /form\.unit = KIND_STORED_UNIT\[kind\];/);
    assert.match(editor.script, /listIngredients\(\{ includePrep: true \}\)/);
    assert.match(editor.template, /recipeUnitOptions\(ingredientByUuid\(line\.ingredient_uuid\), locale\)/);
    assert.match(editor.template, /data-test="prep-live-cost"/);
    assert.match(editor.template, /data-test="prep-readonly"/);
    assert.match(editor.template, /<RecipeHistoryPanel v-if="isEdit"/);
    const inventory = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(inventory.template, /<PrepItemsTab v-if="activeTab === 'prep_items'" \/>/);
    assert.match(inventory.template, /data-test="waste-prep-items"/);
    assert.match(inventory.script, /listIngredients\(\{ includePrep: true \}\)\)\.data\.filter\(\(i\) => i\.is_prep\)/);
    // The stock screens keep the plain list (no prep items).
    assert.match(inventory.script, /const response = await listIngredients\(\);/);
    assert.match(read('resources/js/lib/api/inventory.ts'), /options\.includePrep \? \{ query: \{ include_prep: 1 \} \} : \{\}/);
});

test('P3-5 the Recipe & Cost report shows what was sold in the filters and no developer placeholder', () => {
    const { template } = sfc('resources/js/Pages/Merchant/Reports/RecipeCost.vue');
    assert.doesNotMatch(template, /_phase|trend_stub/);
    assert.match(template, /r\.units_sold/);
    assert.match(template, /r\.actual_cost_per_unit/);
    assert.match(template, /data-test="recipe-cost-filters-hint"/);
    assert.doesNotMatch(read('resources/js/lib/api/reports.ts'), /trend_stub/);
});

test('every LAUNCH-P3 string exists in English and Arabic', () => {
    const en = JSON.parse(read('resources/js/locales/en.json'));
    const ar = JSON.parse(read('resources/js/locales/ar.json'));
    const leaves = (tree, prefix) => Object.entries(tree).flatMap(([k, v]) => (typeof v === 'object' ? leaves(v, `${prefix}.${k}`) : [`${prefix}.${k}`]));
    const keys = [
        ...leaves(en.recipe_units, 'recipe_units'),
        ...leaves(en.recipe_permission, 'recipe_permission'),
        ...leaves(en.recipe_history, 'recipe_history'),
        ...leaves(en.prep_items, 'prep_items'),
        'inventory.tabs.prep_items', 'catalogue.consumption.none',
        'reports.recipe_cost.columns.units_sold', 'reports.recipe_cost.columns.actual_cost_per_unit',
        'reports.recipe_cost.columns.cost_change_per_unit', 'reports.recipe_cost.filters_hint', 'reports.shared.cogs_scope_hint',
    ];
    assert.ok(keys.length >= 60, `${keys.length} keys`);
    const get = (tree, key) => key.split('.').reduce((node, part) => node?.[part], tree);
    for (const key of keys) {
        assert.equal(typeof get(en, key), 'string', `en ${key}`);
        assert.equal(typeof get(ar, key), 'string', `ar ${key}`);
        assert.notEqual(get(ar, key), get(en, key), `ar ${key} is translated`);
    }
});
