// LAUNCH — item kind for ingredients (work order LAUNCH-P23, Part A).
//   A1 the create form asks what KIND of item it is (Weighed / Liquid /
//      Counted → stored in g / ml / piece), never a base unit;
//   A2 the edit form shows the kind, locked once the ingredient is used, with
//      a "stored in kg" note for an older kg / l / pack / box ingredient;
//   A3 optional "How do you buy it?" pack sizes on create, "holds [amount]
//      [unit]" with the unit limited to the kind, sent with the ingredient;
//   A4 the edit form's "Alternate units" become "Pack sizes" ("holds 12 l"),
//      with the same holds input and no factor field;
//   A5 the count container (piece unit) is typed as "bottle holds 1.5 l";
//   A6 the prep item form asks the kind; the yield is typed in its units;
//   A7 every amount picker offers the kind's units, the pack sizes and the
//      count container; the portal count gets a unit per row;
//   A8 inventory screens, recipe and prep lines show 1000 g / ml and above
//      in kg / l ("24 l", not "24000.000 ml");
//   every new string exists in English AND Arabic.
// Run: node --test tests/frontend/launch-p3-kind.test.mjs
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
const leaves = (tree, prefix) => Object.entries(tree).flatMap(([k, v]) => (typeof v === 'object' ? leaves(v, `${prefix}.${k}`) : [`${prefix}.${k}`]));

/** The ingredient create / edit form of the Inventory page. */
function ingredientForm() {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    const start = template.indexOf('id="ing-modal-form"');
    assert.ok(start > 0, 'ingredient form');
    return { script, form: template.slice(start, template.indexOf('</form>', start)) };
}

test('A1 a new ingredient is asked what kind of item it is and is stored in g, ml or piece', () => {
    const { ITEM_KINDS, KIND_STORED_UNIT, kindOfUnit, storedUnitForKind } = lib('itemKind');
    assert.deepEqual([...ITEM_KINDS], ['weighed', 'liquid', 'counted']);
    assert.deepEqual({ ...KIND_STORED_UNIT }, { weighed: 'g', liquid: 'ml', counted: 'piece' });
    // Existing data is not converted: the kind is read from the stored unit.
    for (const [unit, kind] of [['g', 'weighed'], ['kg', 'weighed'], ['ml', 'liquid'], ['l', 'liquid'], ['piece', 'counted'], ['pack', 'counted'], ['box', 'counted']]) {
        assert.equal(kindOfUnit(unit), kind, unit);
    }
    assert.equal(storedUnitForKind('liquid', null), 'ml');
    assert.equal(storedUnitForKind('counted', 'g'), 'piece');
    // Back to an older ingredient's own kind keeps its stored unit.
    assert.equal(storedUnitForKind('weighed', 'kg'), 'kg');

    const { script, form } = ingredientForm();
    assert.match(form, /<fieldset[^>]*data-test="item-kind"/);
    assert.match(form, /item_kind\.question/);
    assert.match(form, /v-for="k in ITEM_KINDS"/);
    assert.match(form, /item_kind\.kinds\.\$\{k\}/);
    assert.match(form, /item_kind\.examples\.\$\{k\}/);
    assert.match(form, /@change="chooseKind\(k\)"/);
    // The create form has no base-unit dropdown any more.
    assert.doesNotMatch(form, /smallUnitOptions|otherUnitOptions|group_small|small_unit_hint/);
    assert.match(script, /ingForm\.unit = storedUnitForKind\(kind, ingModalTarget\.value\?\.unit \?\? null\) as IngredientUnit;/);
    // Nothing is preselected, and nothing is saved before a kind is chosen.
    assert.match(script, /ingForm\.name_ar = '';\s*\/\/[^\n]*\n\s*ingForm\.unit = '';/);
    assert.match(script, /if \(ingForm\.unit === ''\) \{\s*ingModalErrors\.value = \{ unit: \[t\('item_kind\.choose'\)\] \};/);
    // The cost says which unit it is per.
    assert.match(form, /t\('item_kind\.cost_per', \{ unit: ingForm\.unit \}\)/);

    assert.equal(en.item_kind.examples.weighed, 'Rice, cheese, coffee beans');
    assert.equal(en.item_kind.examples.liquid, 'Milk, oil, syrup');
    assert.equal(en.item_kind.examples.counted, 'Eggs, cups, buns');
    for (const key of leaves(en.item_kind, 'item_kind')) {
        assert.doesNotMatch(get(en, key), /base unit/i, `${key} has no "base unit" wording`);
    }
});

test('A2 the edit form shows the kind, locks it on a used ingredient and notes an older stored unit', () => {
    const { isLegacyStoredUnit } = lib('itemKind');
    for (const unit of ['kg', 'l', 'pack', 'box']) assert.equal(isLegacyStoredUnit(unit), true, unit);
    for (const unit of ['g', 'ml', 'piece']) assert.equal(isLegacyStoredUnit(unit), false, unit);

    const { script, form } = ingredientForm();
    // One kind question for create AND edit: the base-unit dropdown is gone.
    assert.match(form, /<fieldset data-test="item-kind" :disabled="kindLocked">/);
    assert.doesNotMatch(form, /v-model="ingForm\.unit"/);
    assert.doesNotMatch(script, /const unitOptions: IngredientUnit\[\]/);
    // Locked from the server's unit-change rule, with the existing explanation.
    assert.match(script, /const kindLocked = computed<boolean>\(\(\) => ingModalMode\.value === 'edit' && ingModalTarget\.value\?\.unit_locked === true\);/);
    assert.match(script, /function chooseKind\(kind: ItemKind\): void \{\s*if \(kindLocked\.value\) return;/);
    assert.match(form, /:disabled="kindLocked"/);
    assert.match(form, /v-if="kindLocked"[^>]*data-test="item-kind-locked">\{\{ t\('item_kind\.locked'\) \}\}/);
    // An older kg / l / pack / box ingredient: its kind + "Stored in kg".
    assert.match(form, /v-if="legacyStoredUnit"[^>]*data-test="item-kind-stored-in">\{\{ t\('item_kind\.stored_in', \{ unit: legacyStoredUnit \}\) \}\}/);
    assert.match(read('resources/js/lib/api/inventory.ts'), /unit_locked\?: boolean;/);
    assert.match(en.item_kind.locked, /already has stock, movements, or recipe\/add-on usage/);
});

test('A3 a new ingredient takes optional pack sizes ("crate holds 12 l") in the same request', () => {
    const { kindUnits } = lib('itemKind');
    const plain = (units) => units.map((u) => `${u.value}=${u.factor}`).join(' ');
    assert.equal(plain(kindUnits('ml')), 'l=1000 ml=1');
    assert.equal(plain(kindUnits('g')), 'kg=1000 g=1');
    assert.equal(plain(kindUnits('kg')), 'kg=1 g=0.001');
    assert.equal(plain(kindUnits('piece')), 'piece=1');
    assert.equal(plain(kindUnits('box')), 'box=1');
    assert.equal(kindUnits('').length, 0);

    const { script, form } = ingredientForm();
    const create = form.slice(form.indexOf('data-test="pack-sizes-create"'));
    assert.ok(form.includes('data-test="pack-sizes-create"'));
    assert.match(create, /item_kind\.pack_sizes\.hint/);
    assert.match(create, /v-for="\(pack, i\) in packSizeDrafts"/);
    assert.match(create, /v-model="pack\.name"/);
    assert.match(create, /v-model="pack\.name_ar"/);
    assert.match(create, /t\('item_kind\.holds'\)/);
    assert.match(create, /v-model="pack\.amount"/);
    // The unit picker is limited to the kind; no factor is typed.
    assert.match(create, /<select v-model="pack\.unit"[^>]*>\s*<option v-for="u in holdUnits"/);
    assert.doesNotMatch(create.slice(0, create.indexOf('data-test="add-pack-size"')), /factor/);
    assert.match(script, /const holdUnits = computed<KindUnit\[\]>\(\(\) => kindUnits\(ingForm\.unit\)\);/);
    assert.match(script, /await createIngredient\(packSizes\.length > 0 \? \{ \.\.\.payload, pack_sizes: packSizes \} : payload\);/);
    assert.match(script, /\.map\(\(d\) => \(\{ name: d\.name\.trim\(\), name_ar: d\.name_ar\.trim\(\) \|\| null, amount: String\(d\.amount \?\? ''\)\.trim\(\), unit: d\.unit \}\)\);/);
    assert.match(read('resources/js/lib/api/inventory.ts'), /pack_sizes\?: \{ name: string; name_ar\?: string \| null; amount: string \| number; unit: string \}\[\];/);
});

test('A4 the edit form lists Pack sizes as "holds 12 l", edited as holds [amount] [unit] with no factor field', () => {
    const { friendlyAmount, holdsEntry, trimAmount } = lib('itemKind');
    const plain = (x) => `${x.amount} ${x.unit}`;
    assert.equal(plain(friendlyAmount('12000.0000', 'ml')), '12 l');
    assert.equal(plain(friendlyAmount('24.0000', 'piece')), '24 piece');
    assert.equal(plain(holdsEntry('12000.0000', 'ml')), '12 l');
    assert.equal(plain(holdsEntry('25000.0000', 'g')), '25 kg');
    assert.equal(plain(holdsEntry('0.5000', 'kg')), '0.5 kg');
    // Reopens exactly: a factor the big unit cannot carry at 4 decimals stays in the stored unit.
    assert.equal(plain(holdsEntry('1234.5678', 'ml')), '1234.5678 ml');
    assert.equal(plain(holdsEntry('1234.5000', 'ml')), '1.2345 l');
    assert.equal(trimAmount(-0.00001), '0');

    const { script, form } = ingredientForm();
    const edit = form.slice(form.indexOf('<template v-else>', form.indexOf('data-test="pack-sizes-create"')));
    assert.match(form, /t\('item_kind\.pack_sizes\.title_edit'\)/);
    assert.match(edit, /data-test="pack-sizes-edit"/);
    assert.match(edit, /\{\{ packHoldsText\(unit\) \}\}/);
    assert.match(edit, /v-model="altUnitDrafts\[unit\.uuid\]\.amount"/);
    assert.match(edit, /v-model="altUnitDrafts\[unit\.uuid\]\.unit"/);
    assert.match(edit, /v-model="altUnitNew\.amount"/);
    assert.match(edit, /<select v-model="altUnitNew\.unit"[^>]*>\s*<option v-for="u in savedHoldUnits"/);
    // No factor is typed, and no "base unit" text is shown.
    assert.doesNotMatch(edit, /\.factor"|alt_units\.factor|base_unit_label|alt_units\.hint/);
    assert.match(script, /amount: String\(altUnitNew\.amount\)\.trim\(\),\s*unit: altUnitNew\.unit,/);
    assert.match(script, /amount: String\(draft\.amount\)\.trim\(\),\s*unit: draft\.unit,/);
    assert.match(script, /const holds = holdsEntry\(u\.factor, ingModalTarget\.value\?\.unit\);/);
    assert.match(script, /const savedHoldUnits = computed<KindUnit\[\]>\(\(\) => kindUnits\(ingModalTarget\.value\?\.unit\)\);/);
    assert.equal(en.item_kind.holds_amount, 'holds {amount}');
});

test('A5 the count container is typed as what it holds ("bottle holds 1.5 l"), not "1500 ml per piece"', () => {
    const { toStoredAmount } = lib('itemKind');
    assert.equal(toStoredAmount('1.5', 'l', 'ml'), 1500);
    assert.equal(toStoredAmount('330', 'ml', 'ml'), 330);
    assert.equal(toStoredAmount('330', 'g', 'kg'), 0.33);
    assert.equal(toStoredAmount('24', 'piece', 'piece'), 24);
    assert.equal(toStoredAmount('0', 'l', 'ml'), null);
    assert.equal(toStoredAmount('5', 'kg', 'ml'), null, 'not a unit of the kind');
    assert.equal(toStoredAmount('0.00001', 'g', 'kg'), null, 'rounds away at 4 decimals');

    const { script, form } = ingredientForm();
    const container = form.slice(form.indexOf('data-test="count-container"'), form.indexOf('</fieldset>', form.indexOf('data-test="count-container"')));
    assert.ok(container.length > 0);
    assert.match(container, /item_kind\.container\.title/);
    assert.match(container, /v-model="ingForm\.piece_unit_label"/);
    assert.match(container, /t\('item_kind\.holds'\)/);
    assert.match(container, /v-model="ingForm\.container_amount"/);
    assert.match(container, /<select v-model="ingForm\.container_unit"[^>]*>\s*<option v-for="u in holdUnits"/);
    assert.doesNotMatch(container, /units_per_piece'|ingForm\.units_per_piece|piece\.units_per_piece/);
    assert.match(script, /units_per_piece: containerUnitsPerPiece\(unit\),/);
    assert.match(script, /const stored = toStoredAmount\(text, ingForm\.container_unit, storedUnit\);/);
    assert.match(script, /const holds = holdsEntry\(ingredient\.units_per_piece, ingredient\.unit\);/);
});

test('A6 the prep item form asks the kind and takes the yield in the kind\'s units ("one batch makes 2 l")', () => {
    const { toStoredAmount, holdsEntry, trimAmount } = lib('itemKind');
    assert.equal(trimAmount(toStoredAmount('2', 'l', 'ml')), '2000');
    assert.equal(trimAmount(toStoredAmount('1.25', 'kg', 'g')), '1250');
    assert.equal(trimAmount(toStoredAmount('12', 'piece', 'piece')), '12');
    const reopen = holdsEntry('2000.0000', 'ml');
    assert.equal(`${reopen.amount} ${reopen.unit}`, '2 l');

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue');
    // The g / ml / piece dropdown is replaced by the kind question.
    assert.doesNotMatch(template, /<select v-model="form\.unit"/);
    assert.doesNotMatch(script, /const prepUnits/);
    assert.match(template, /data-test="prep-kind"/);
    assert.match(template, /v-for="k in ITEM_KINDS"/);
    assert.match(template, /@change="chooseKind\(k\)"/);
    assert.match(template, /:disabled="!canEditRecipes \|\| unitLocked"/);
    assert.match(script, /form\.unit = KIND_STORED_UNIT\[kind\];/);
    // The yield: an amount + a unit of the kind, kept in the stored unit.
    assert.match(template, /<select v-model="form\.yield_unit"[^>]*data-test="prep-yield-unit"[^>]*>\s*<option v-for="u in yieldUnits"/);
    assert.match(script, /const yieldUnits = computed\(\(\) => kindUnits\(form\.unit\)\);/);
    assert.match(script, /const yieldNumber = computed<number \| null>\(\(\) => toStoredAmount\(form\.prep_yield_quantity, form\.yield_unit, form\.unit\)\);/);
    assert.match(script, /prep_yield_quantity: yieldNumber\.value === null \? String\(form\.prep_yield_quantity\)\.trim\(\) : trimAmount\(yieldNumber\.value\),/);
    assert.match(script, /const batch = holdsEntry\(item\.data\.prep_yield_quantity, item\.data\.unit\);/);
    assert.doesNotMatch(en.prep_items.editor_hint, /base unit/);
    assert.doesNotMatch(template, /prep_items\.yield_hint|prep_items\.unit_hint|prep_items\.fields\.unit/);
});

test('A7 waste, adjust, transfers, restock requests and the count offer the kind\'s units, pack sizes and the container', () => {
    const { entryUnitOptions, entryUnitFactor, PIECE_UNIT } = lib('itemKind');
    const milk = {
        unit: 'ml',
        auto_units: [{ name: 'l', factor: '1000' }],
        alt_units: [{ name: 'crate', factor: '12000.0000' }],
        piece_unit_label: 'bottle',
        piece_unit_label_ar: 'زجاجة',
        units_per_piece: '1500.0000',
    };
    const options = entryUnitOptions(milk, 'en');
    assert.deepEqual([...options.map((o) => o.value)], ['', 'crate', 'l', PIECE_UNIT]);
    assert.deepEqual([...options.map((o) => o.label)], ['ml', 'crate (12 l)', 'l', 'bottle (1.5 l)']);
    assert.equal(entryUnitOptions(milk, 'ar')[3].label, 'زجاجة (1.5 l)');
    assert.equal(entryUnitFactor(milk, PIECE_UNIT), 1500);
    assert.equal(entryUnitFactor(milk, 'crate'), 12000);
    assert.equal(entryUnitFactor(milk, 'l'), 1000);
    assert.equal(entryUnitFactor(milk, ''), 1);
    // A piece-stored item whose "container" is one piece has no separate container option.
    assert.deepEqual([...entryUnitOptions({ unit: 'piece', piece_unit_label: 'egg', units_per_piece: '1' }).map((o) => o.value)], ['']);

    const inventoryApi = read('resources/js/lib/api/inventory.ts');
    assert.match(inventoryApi, /return entryUnitOptions\(ingredient, locale\)\.map\(\(\{ value, label \}\) => \(\{ value, label \}\)\);/);
    assert.match(inventoryApi, /return entryUnitFactor\(ingredient, selected\);/);
    assert.match(inventoryApi, /unit\?: string \| null;\s*\}\s*\n\s*export interface SubmitStockCountPayload/);

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    for (const source of ['adjustTarget\\.ingredient', 'restockTarget\\.ingredient', 'wasteIngredient', 'ingredientByUuid\\(line\\.ingredient_uuid\\)']) {
        assert.match(template, new RegExp(`ingredientUnitOptions\\(${source}, locale\\)`), source);
    }
    assert.doesNotMatch(template, /ingredientUnitOptions\([^,)]*\)"/, 'every picker passes the locale');
    // The count: a unit per row (the container first when there is one).
    const count = template.slice(template.indexOf('id="count-modal-form"'), template.indexOf('</form>', template.indexOf('id="count-modal-form"')));
    assert.match(count, /<select v-model="countRows\[i\]\.unit"[^>]*data-test="count-unit"[^>]*>\s*<option v-for="u in ingredientUnitOptions\(r\.ingredient, locale\)"/);
    assert.match(script, /\.map\(\(ingredient\) => \(\{ ingredient, counted: '', unit: defaultCountUnit\(ingredient\) \}\)\);/);
    assert.match(script, /if \(countsPieces\(r\)\) return \{ ingredient_uuid: r\.ingredient\.uuid, counted_pieces: r\.counted \};/);
    assert.match(script, /: \{ ingredient_uuid: r\.ingredient\.uuid, counted_units: r\.counted, unit: r\.unit \};/);
    // The minimum stock is typed in a unit of the kind too.
    const { form } = ingredientForm();
    assert.match(form, /<select v-model="ingForm\.min_stock_unit"[^>]*data-test="min-stock-unit"[^>]*>\s*<option v-for="u in holdUnits"/);
    assert.match(script, /min_stock_threshold: minimumInStoredUnit\(unit\),/);
});

test('A8 inventory screens show 1000 g / ml and above in kg / l, up to 4 decimals, trailing zeros trimmed', () => {
    const { friendlyAmount } = lib('itemKind');
    const plain = (q, u) => { const f = friendlyAmount(q, u); return `${f.amount} ${f.unit}`; };
    assert.equal(plain('24000.000', 'ml'), '24 l');
    assert.equal(plain('999.500', 'g'), '999.5 g');
    assert.equal(plain('1000.000', 'g'), '1 kg');
    assert.equal(plain('-1500.000', 'ml'), '-1.5 l');
    assert.equal(plain('1234.5678', 'g'), '1.2346 kg');
    assert.equal(plain('0.2500', 'kg'), '0.25 kg');
    assert.equal(plain('12.000', 'piece'), '12 piece');

    // Recipe and prep lines in the stored unit read the same way; typed in another unit they stay as typed.
    const { lineAmountText, recipeUnitOptions } = lib('recipeUnits');
    const flour = { unit: 'g', alt_units: [{ name: 'sack', factor: '25000.0000' }], auto_units: [{ name: 'kg', factor: '1000' }] };
    assert.equal(lineAmountText(flour, '', '1500.0000'), '1.5 kg');
    assert.equal(lineAmountText(flour, '', '150'), '150 g');
    assert.equal(lineAmountText(flour, 'kg', '1.5'), '1.5 kg');
    assert.equal(lineAmountText(flour, 'sack', '2'), '2 sack');
    assert.equal(recipeUnitOptions(flour).find((o) => o.value === 'sack').label, 'sack (25 kg)');
    const { purchaseUnitOptions } = lib('purchaseUnits');
    assert.equal(purchaseUnitOptions(flour).find((o) => o.value === 'sack').label, 'sack (25 kg)');

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(script, /function qty\(quantity: string \| number \| null \| undefined, unit: string \| null \| undefined\): string \{\s*const friendly = friendlyAmount\(quantity, unit\);/);
    // Ingredient list: the kind (and an older stored unit), the minimum.
    assert.match(template, /t\('item_kind\.column'\)/);
    assert.match(template, /data-test="ingredient-kind">\s*\{\{ t\(`item_kind\.kinds\.\$\{kindOfUnit\(ing\.unit\)\}`\) \}\}/);
    assert.match(template, /qty\(ing\.min_stock_threshold, ing\.unit\)/);
    // Branch stock (and its minimum), movements, waste, counts, transfers, restock requests.
    for (const pattern of [
        /friendlyAmount\(row\.quantity, row\.ingredient\?\.unit\)\.amount/,
        /qty\(row\.ingredient\.min_stock_threshold, row\.ingredient\.unit\)/,
        /friendlyAmount\(m\.quantity, m\.ingredient\?\.unit\)\.amount/,
        /-\{\{ friendlyAmount\(w\.quantity, w\.unit_at_set\)\.amount \}\}/,
        /qty\(line\.counted_units, line\.ingredient\?\.unit\)/,
        /qty\(line\.expected_units, line\.ingredient\?\.unit\)/,
        /qty\(line\.variance_units, line\.ingredient\?\.unit\)/,
        /\(\{\{ qty\(l\.quantity, l\.unit\) \}\}\)/,
        /friendlyAmount\(l\.quantity_requested, l\.unit_at_set\)\.amount/,
        /qty\(row\.suggestion\.current_quantity, row\.suggestion\.unit\)/,
    ]) {
        assert.match(template, pattern, String(pattern));
    }
    assert.doesNotMatch(template, /\{\{ row\.quantity \}\}|\{\{ m\.quantity \}\}|\{\{ line\.expected_units \}\}|\{\{ line\.variance_units \}\}/);
    // Prep list and recipe history yields; read-only recipe / option / prep lines.
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemsTab.vue').template, /data-test="prep-yield">\{\{ yieldText\(item\) \}\}/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/RecipeHistoryPanel.vue').template, /\{\{ yieldText\(v\.yield_before\) \}\} → <\/template>\{\{ yieldText\(v\.yield_after\) \}\}/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue').script, /return lineAmountText\(ingredientByUuid\(line\.ingredient_uuid\), line\.unit, line\.quantity, locale\.value\);/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').script, /return lineAmountText\(ingredientByUuid\(line\.ingredient_uuid\), line\.unit, line\.quantity, locale\.value\);/);
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/AddonConsumptionEditor.vue').script, /amount: lineAmountText\(ingredient, line\.unit \?\? '', line\.quantity, locale\.value\)/);
});

test('every item-kind string exists in English and Arabic', () => {
    const keys = leaves(en.item_kind, 'item_kind');
    assert.ok(keys.length >= 10, `${keys.length} keys`);
    for (const key of keys) {
        assert.equal(typeof get(en, key), 'string', `en ${key}`);
        assert.equal(typeof get(ar, key), 'string', `ar ${key}`);
        assert.notEqual(get(ar, key), get(en, key), `ar ${key} is translated`);
    }
});
