// LAUNCH-P3 fix order 1, Part A — merchant portal screens.
//   L3  a type change that switches recipe deduction needs "Edit recipes"; the
//       review step says the recipe is removed (the server clears it);
//   L4  amounts in the recipe history and read-only lines are isolated
//       left-to-right, so "150 g → 120 g" never garbles in Arabic;
//   L5  a picked ingredient with a blank amount blocks saving (it used to be
//       dropped, deleting the line);
//   L8  one rule for recipe writes ("Edit recipes" + catalogue view), a
//       read-only product view with history, a Roles-page hint;
//   K3  waste is recorded below zero with a warning (never blocked);
//   K4  Loss & Waste lists prep items wasted, one event each;
//   K8  the piece unit's Arabic label; an option's stock usage is not saved
//       while a line has a problem;
//   K9  a legacy 0 line is flagged "enter an amount or remove this line";
//   UI-1 the prep list shows the batch cost as money (3 decimals);
//   UI-2 no stale "next phase" Catalogue header;
//   UI-3 the Cooked help text no longer says it starts sold out.
// Run: node --test tests/frontend/launch-p3-fix1.test.mjs
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

/** Load a lib/*.ts file as a plain module (exports collected from `export`). */
function lib(name) {
    const { outputText } = ts.transpileModule(read(`resources/js/lib/${name}.ts`), {
        compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
    });
    const module = { exports: {} };
    runInNewContext(outputText, { module, exports: module.exports, Math, Number, String, Set, parseFloat });
    return module.exports;
}

const en = JSON.parse(read('resources/js/locales/en.json'));
const ar = JSON.parse(read('resources/js/locales/ar.json'));
const get = (tree, key) => key.split('.').reduce((node, part) => node?.[part], tree);

const garlic = { unit: 'g', alt_units: [], auto_units: [{ name: 'kg', factor: '1000' }] };
const lemon = { unit: 'ml', alt_units: [], auto_units: [] };
const bread = {
    unit: 'g', alt_units: [], auto_units: [], piece_unit_label: 'loaf', piece_unit_label_ar: 'رغيف', units_per_piece: '500.0000',
};

test('L5 a picked ingredient with a blank amount is a line problem that blocks saving (Toum)', () => {
    const { recipeLineProblem, recipeLinesHaveProblems } = lib('recipeUnits');
    const byUuid = { garlic, lemon };
    const find = (uuid) => byUuid[uuid] ?? null;
    // Toum: garlic picked but its amount cleared, lemon fine.
    const toum = [
        { ingredient_uuid: 'garlic', quantity: '', unit: '' },
        { ingredient_uuid: 'lemon', quantity: '120', unit: '' },
    ];
    assert.equal(recipeLinesHaveProblems(toum, find), true);
    assert.equal(recipeLineProblem(garlic, '', '', true).key, 'recipe_units.amount_required');
    // A line with nothing picked is just left out; a complete recipe saves.
    assert.equal(recipeLinesHaveProblems([{ ingredient_uuid: '', quantity: '', unit: '' }, toum[1]], find), false);
    assert.equal(recipeLinesHaveProblems([{ ingredient_uuid: 'garlic', quantity: '200', unit: '' }, toum[1]], find), false);

    // The prep editor's Save is disabled by it (canSave needs !hasProblems).
    const editor = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    assert.match(editor.script, /const hasProblems = computed<boolean>\(\(\) => recipeLinesHaveProblems\(form\.lines, ingredientByUuid\)\);/);
    assert.match(editor.script, /const canSave = computed<boolean>\(\(\) => canEditRecipes\.value[\s\S]*&& !hasProblems\.value/);
    assert.match(editor.template, /:disabled="!canSave"/);

    // The product wizard no longer drops it from the payload; submit is blocked first.
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.doesNotMatch(wizard.script, /\.filter\(\(l\) => l\.ingredient_uuid && l\.quantity !== ''\)/);
    assert.match(wizard.script, /recipeLinesHaveProblems\(form\.recipe_lines, ingredientByUuid\)/);
});

test('L5 + K8 an option line with an item picked but no amount is kept and blocks the save', () => {
    const { completeConsumptionLines, consumptionLinesHaveProblems, consumptionLineProblem } = lib('recipeUnits');
    const find = (uuid) => ({ garlic }[uuid] ?? null);
    const lines = [
        { type: 'ingredient', ingredient_uuid: 'garlic', direction: 'add', quantity: '', unit: '' },
        { type: 'product', product_uuid: 'cup', direction: 'add', quantity: '0' },
        { type: 'ingredient', ingredient_uuid: '', direction: 'add', quantity: '', unit: '' },
    ];
    // Only the line with nothing picked is left out — never a picked one.
    assert.equal(completeConsumptionLines(lines).length, 2);
    assert.equal(consumptionLinesHaveProblems(lines, find), true);
    assert.equal(consumptionLineProblem(lines[1], find).key, 'recipe_units.amount_required');
    assert.equal(consumptionLinesHaveProblems([{ type: 'ingredient', ingredient_uuid: 'garlic', direction: 'add', quantity: '9', unit: '' }], find), false);

    const catalogue = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(catalogue.script, /if \(canEditRecipes\.value && consumptionLinesHaveProblems\(aoConsumption\.value/);
    assert.doesNotMatch(catalogue.script, /function completeConsumptionLines/);
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(wizard.script, /if \(consumptionLinesHaveProblems\(optionStockDrafts\.value\[uuid\] \?\? \[\], ingredientByUuid\)\)/);
    assert.doesNotMatch(wizard.script, /function completeConsumptionLines/);
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue');
    assert.match(editor.script, /consumptionLineProblem\(line, /);
});

test('K9 a legacy zero-quantity line is flagged "enter an amount or remove this line"', () => {
    const { lineEntry, recipeLineProblem } = lib('recipeUnits');
    const legacy = lineEntry({ quantity: '0.0000', entered_unit: null, entered_quantity: null }, 'kg');
    assert.equal(legacy.quantity, '0');
    assert.equal(recipeLineProblem({ unit: 'kg', alt_units: [], auto_units: [] }, legacy.unit, legacy.quantity).key, 'recipe_units.amount_required');
    assert.equal(en.recipe_units.amount_required, 'Enter an amount or remove this line.');
    assert.equal(typeof ar.recipe_units.amount_required, 'string');
    assert.notEqual(ar.recipe_units.amount_required, en.recipe_units.amount_required);
});

test('K8 the piece unit shows its Arabic label in Arabic', () => {
    const { recipeUnitOptions, recipeUnitName, PIECE_UNIT } = lib('recipeUnits');
    assert.equal(recipeUnitOptions(bread, 'ar').find((o) => o.value === PIECE_UNIT).label, 'رغيف (500 g)');
    assert.equal(recipeUnitOptions(bread, 'en').find((o) => o.value === PIECE_UNIT).label, 'loaf (500 g)');
    assert.equal(recipeUnitName(bread, PIECE_UNIT, 'ar'), 'رغيف');
    assert.equal(recipeUnitName({ ...bread, piece_unit_label_ar: null }, PIECE_UNIT, 'ar'), 'loaf');
    // Every picker passes the reader's locale.
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').template, /recipeUnitOptions\(ingredientByUuid\(line\.ingredient_uuid\), locale\)/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').template, /recipeUnitOptions\(ingredientByUuid\(line\.ingredient_uuid\), locale\)/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue').script, /recipeUnitOptions\(ingredient, locale\.value\)/);
});

test('L4 recipe amounts are isolated left-to-right in the history and the read-only lines', () => {
    const history = sfc('resources/js/Pages/Merchant/Catalogue/RecipeHistoryPanel.vue').template;
    assert.match(history, /<bdi dir="ltr" class="tabular-nums"><template v-if="v\.yield_before">/);
    assert.match(history, /<bdi dir="ltr" class="tabular-nums text-slate-600" data-test="recipe-history-amount">\s*<template v-if="c\.change === 'changed'">\{\{ c\.before \}\} → \{\{ c\.after \}\}<\/template>/);
    const wizard = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').template;
    assert.match(wizard, /<li v-for="\(line, idx\) in form\.recipe_lines" :key="idx" class="text-sm text-slate-700"><bdi dir="ltr" class="tabular-nums">\{\{ recipeLineAmount\(line\) \}\}<\/bdi>/);
    assert.match(wizard, /<bdi dir="ltr" class="tabular-nums">\{\{ recipeLineAmount\(line\) \}\}<\/bdi>\s*<\/li>/);
    const prep = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').template;
    assert.match(prep, /<bdi dir="ltr" class="tabular-nums">\{\{ lineAmount\(line\) \}\}<\/bdi>/);
    const option = sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue').template;
    assert.match(option, /<bdi dir="ltr" class="tabular-nums">\{\{ readonlyParts\(line\)\.amount \}\}<\/bdi>/);
});

test('L8 one rule for recipe writes: "Edit recipes" + catalogue view', () => {
    const { canWriteRecipes, MerchantPermission } = lib('permissions');
    const holding = (...perms) => (p) => perms.includes(p);
    assert.equal(canWriteRecipes(holding(MerchantPermission.CatalogueView, MerchantPermission.CatalogueRecipesManage)), true);
    assert.equal(canWriteRecipes(holding(MerchantPermission.CatalogueRecipesManage)), false);
    assert.equal(canWriteRecipes(holding(MerchantPermission.CatalogueView, MerchantPermission.CatalogueManage)), false);
    for (const path of [
        'resources/js/Pages/Merchant/Catalogue/ProductWizard.vue',
        'resources/js/Pages/Merchant/Catalogue/Index.vue',
        'resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue',
        'resources/js/Pages/Merchant/Inventory/PrepItemsTab.vue',
    ]) {
        assert.match(sfc(path).script, /const canEditRecipes = computed\(\(\) => canWriteRecipes\(can\)\);/, path);
    }
});

test('L8 a catalogue viewer opens a product read-only with its recipe history; a recipe editor saves only the recipe', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(script, /const readOnly = computed\(\(\) => !canManage\.value\);/);
    assert.match(script, /const canOpen = computed\(\(\) => canManage\.value \|\| \(isEdit && canView\.value\)\);/);
    assert.match(script, /const canSubmit = computed\(\(\) => canManage\.value \|\| \(isEdit && canEditRecipes\.value\)\);/);
    assert.match(template, /<div v-if="!canOpen"/);
    assert.match(template, /<fieldset v-if="step === 1" :disabled="readOnly"/);
    assert.match(template, /data-test="product-readonly"/);
    // The history is not gated on catalogue.manage.
    assert.match(template, /<RecipeHistoryPanel v-if="isEdit && hasRecipeStep"/);
    assert.match(template, /v-else-if="canSubmit"/);
    // A recipe-only save sends the recipe and nothing else.
    assert.match(script, /\} else if \(readOnly\.value\) \{\s*\/\/[^\n]*\n[^\n]*\n\s*await updateProductRecipe\(editUuid!, \{ lines: recipePayload\(\), note: recipeNote \}\)/);

    const catalogue = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(catalogue.template, /data-test="product-open"/);
    assert.match(catalogue.template, /canManage \? t\('catalogue\.actions\.edit'\) : t\('catalogue\.actions\.view'\)/);
    assert.match(catalogue.template, /<button v-if="canManage \|\| canEditRecipes" type="button"[^>]*@click="openEditAddOn\(group, addon\)"/);
    assert.match(catalogue.template, /<fieldset :disabled="!canManage" class="min-w-0 space-y-4" data-test="addon-fields">/);
    assert.match(catalogue.script, /await updateAddOn\(aoModalTarget\.value\.uuid, \{ consumption: completeConsumptionLines\(aoConsumption\.value\) \}\);/);

    const roles = sfc('resources/js/Pages/Merchant/Roles/Index.vue').template;
    assert.match(roles, /editorForm\.permissions\.has\(MerchantPermission\.CatalogueRecipesManage\) && !editorForm\.permissions\.has\(MerchantPermission\.CatalogueView\)/);
    assert.match(roles, /t\('roles\.editor\.recipes_need_view'\)/);
});

test('L3 a type change that switches recipe deduction needs "Edit recipes", and the review says the recipe is removed', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(script, /function typeLocked\(mode: string\): boolean \{/);
    assert.match(script, /return RECIPE_MODES\.includes\(savedMode\) \|\| RECIPE_MODES\.includes\(mode\);/);
    assert.match(template, /:disabled="typeLocked\(mode\)"/);
    assert.match(template, /data-test="type-locked"/);
    assert.match(template, /isEdit \? t\('catalogue\.wizard\.recipe_removed'\) : t\('catalogue\.wizard\.recipe_dropped'\)/);
    assert.match(en.catalogue.wizard.recipe_removed, /saving removes the recipe lines/);
    assert.match(en.catalogue.wizard.type_locked_hint, /Edit recipes/);
});

test('K3 + K4 waste is recorded with a warning (never blocked) and Loss & Waste names prep items', () => {
    const inventory = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.doesNotMatch(inventory.template, /:disabled="wasteBusy \|\| wasteInsufficient"/);
    assert.match(inventory.template, /data-test="waste-warning"/);
    assert.match(inventory.script, /wasteWarning\.value = response\.warning \?\? null;/);
    assert.match(inventory.template, /data-test="waste-prep-item"/);
    const dialog = sfc('resources/js/Pages/Merchant/Catalogue/ProductStockDialog.vue');
    assert.match(dialog.script, /actionWarning\.value = res\.warning \?\? null;/);
    assert.match(dialog.template, /data-test="product-waste-warning"/);
    const report = sfc('resources/js/Pages/Merchant/Reports/LossWaste.vue').template;
    assert.match(report, /<section v-if="payload\.prep_wastes\?\.length"[^>]*data-test="loss-waste-prep-items">/);
    assert.match(report, /\{\{ r\.prep_name \}\}/);
    assert.doesNotMatch(en.inventory.waste.modal.insufficient_warning, /adjust first/);
});

test('UI-1 the prep list shows the batch cost as money, 3 decimals', () => {
    const { money } = lib('recipeUnits');
    assert.equal(money('5.4656'), '5.466');
    assert.equal(money('3.4'), '3.400');
    assert.equal(money(null), '—');
    const tab = sfc('resources/js/Pages/Merchant/Inventory/PrepItemsTab.vue').template;
    assert.match(tab, /\{\{ money\(item\.batch_cost\) \}\}/);
    assert.doesNotMatch(tab, /\{\{ item\.batch_cost \}\}/);
});

test('UI-2 + UI-3 no stale Catalogue header; Cooked never "starts sold out" (EN and AR)', () => {
    assert.doesNotMatch(en.catalogue.subtitle, /next phase/);
    assert.doesNotMatch(ar.catalogue.subtitle, /المرحلة التالية/);
    for (const key of ['catalogue.wizard.types.cooked.desc', 'catalogue.wizard.branches_cooked_hint']) {
        assert.doesNotMatch(get(en, key), /sold out/, `en ${key}`);
        assert.doesNotMatch(get(ar, key), /نافد/, `ar ${key}`);
        assert.match(get(en, key), /below zero/, `en ${key}`);
    }
});

test('every fix-order string exists in English and Arabic', () => {
    const keys = [
        'recipe_units.amount_required', 'recipe_units.too_many_piece_decimals', 'recipe_units.fix_lines',
        'catalogue.wizard.view_title', 'catalogue.wizard.readonly_hint', 'catalogue.wizard.readonly_recipe_hint',
        'catalogue.wizard.type_locked_hint', 'catalogue.wizard.recipe_removed', 'catalogue.actions.view',
        'roles.editor.recipes_need_view', 'inventory.waste.from_prep',
        'reports.loss_waste.prep_wastes.title', 'reports.loss_waste.prep_wastes.hint', 'reports.loss_waste.prep_wastes.prep_item',
    ];
    for (const key of keys) {
        assert.equal(typeof get(en, key), 'string', `en ${key}`);
        assert.equal(typeof get(ar, key), 'string', `ar ${key}`);
        assert.notEqual(get(ar, key), get(en, key), `ar ${key} is translated`);
    }
});
