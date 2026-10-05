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
// Follow-up fixes (browser check of d75a3bd):
//   F1 a Weighed / Liquid cost is typed and shown per kg / l;
//   F2 goods received: pack sizes first, kg / l before g / ml, friendly preview;
//   F3 the receipt detail shows quantities, splits and costs the friendly way;
//   F4 the warehouse dialog takes any unit the item knows;
//   F5 restock allocation and suggestions too, shown friendly;
//   F6 no kind change while pack sizes or a count container exist;
//   F7 report screens show ingredient quantities friendly;
//   F8 no mixed-unit Total qty tile on Loss & Waste / Restock & Purchasing;
// Owner addendum 2026-10-03:
//   G1 gallon / fl oz (Liquid) and lb / oz (Weighed) wherever kg/g or l/ml are;
//   G2 a conversions button on the branch and warehouse stock lists;
//   G3 a "Show in: Auto / kg·l / g·ml" switch on those lists;
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
    // LAUNCH review add-on A1 — no cost box: the cost comes from purchases
    // (it was typed per kg / l here before, F1).
    assert.doesNotMatch(form, /item_kind\.cost_per|ingForm\.cost_unit/);
    assert.match(form, /t\('purchases_v2\.cost_from_purchases'\)/);

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
    // (G1 adds the US units after the metric pair.)
    assert.equal(plain(kindUnits('ml').slice(0, 2)), 'l=1000 ml=1');
    assert.equal(plain(kindUnits('g').slice(0, 2)), 'kg=1000 g=1');
    assert.equal(plain(kindUnits('kg').slice(0, 2)), 'kg=1 g=0.001');
    assert.equal(plain(kindUnits('piece')), 'piece=1');
    assert.equal(plain(kindUnits('box')), 'box=1');
    assert.equal(kindUnits('').length, 0);

    // LAUNCH review add-on A2 — the rows are typed in the ContainersEditor
    // ("Containers — how do you buy it?"), still sent with the new item.
    const { script, form } = ingredientForm();
    assert.match(form, /<ContainersEditor\s[\s\S]*?v-model:drafts="containerDrafts"[\s\S]*?:stored-unit="ingForm\.unit"/);
    const editor = sfc('resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue');
    const create = editor.template.slice(editor.template.indexOf('data-test="container-draft"'), editor.template.indexOf('data-test="add-container"'));
    assert.match(editor.template, /containers\.hint/);
    assert.match(create, /t\('containers\.holds'\)/);
    // The unit picker is limited to the kind; no factor is typed.
    assert.match(create, /<option v-for="u in holdUnits"/);
    assert.doesNotMatch(create, /factor/);
    assert.match(editor.script, /const holdUnits = computed\(\(\) => kindUnits\(props\.storedUnit\)\);/);
    assert.match(script, /const holdUnits = computed<KindUnit\[\]>\(\(\) => kindUnits\(ingForm\.unit\)\);/);
    assert.match(script, /\.\.\.\(containers\.length > 0 \? \{ pack_sizes: containers \} : \{\}\),/);
    assert.match(script, /: \{ name: d\.name\.trim\(\), name_ar: d\.name_ar\.trim\(\) \|\| null, amount: String\(d\.amount \?\? ''\)\.trim\(\), unit: d\.unit, count_container: d\.count_container, barcodes: d\.barcodes \}/);
    assert.match(read('resources/js/lib/api/inventory.ts'), /pack_sizes\?: ContainerDraftPayload\[\];/);
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

    // LAUNCH review add-on A2 — the saved containers are edited in the
    // ContainersEditor: each row reads its server display name ("bottle 1.5 l")
    // and is edited as holds [amount] [unit]; still no factor field.
    const { form } = ingredientForm();
    assert.match(form, /<ContainersEditor\s[\s\S]*?:mode="ingModalMode"[\s\S]*?:ingredient-uuid="ingModalTarget\?\.uuid \?\? null"/);
    const editor = sfc('resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue');
    const edit = editor.template.slice(editor.template.indexOf('data-test="containers-edit"'));
    assert.match(edit, /data-test="container-display-name">\{\{ rowLabel\(row\) \}\}/);
    assert.match(edit, /v-model="edits\[row\.uuid\]\.amount"/);
    assert.match(edit, /<select v-model="edits\[row\.uuid\]\.unit"[^>]*>\s*<option v-for="u in holdUnits"/);
    assert.match(edit, /v-model="fresh\.amount"/);
    assert.match(edit, /<select v-model="fresh\.unit"[^>]*>\s*<option v-for="u in holdUnits"/);
    // No factor is typed, and no "base unit" text is shown.
    assert.doesNotMatch(edit, /\.factor"|alt_units\.factor|base_unit_label|alt_units\.hint/);
    assert.match(editor.script, /: \{ amount: String\(source\.amount\)\.trim\(\), unit: source\.unit \};/);
    assert.match(editor.script, /const holds = holdsEntry\(r\.factor, props\.storedUnit\);/);
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

    // LAUNCH review add-on A3 — the count container is a container row marked
    // "Tills count in this" (typed as what it holds, like every container);
    // the separate count-container fieldset and units_per_piece are gone.
    const { script, form } = ingredientForm();
    assert.doesNotMatch(form, /data-test="count-container"|ingForm\.piece_unit_label|ingForm\.container_amount/);
    assert.doesNotMatch(script, /units_per_piece: /);
    const editor = sfc('resources/js/Pages/Merchant/Inventory/components/ContainersEditor.vue');
    assert.match(editor.template, /data-test="count-marker"/);
    assert.match(editor.template, /t\('containers\.count_marker'\)/);
    assert.match(editor.script, /toStoredAmount\(/);
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
    // (LAUNCH review add-on D2 adds the counted containers after the unit.)
    assert.match(inventoryApi, /unit\?: string \| null;[\s\S]{0,400}containers\?: ContainerRowPayload\[\];\s*\}\s*\n\s*export interface SubmitStockCountPayload/);

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    for (const source of ['adjustTarget\\.ingredient', 'restockTarget\\.ingredient', 'wasteIngredient', 'ingredientByUuid\\(line\\.ingredient_uuid\\)']) {
        assert.match(template, new RegExp(`ingredientUnitOptions\\(${source}, locale\\)`), source);
    }
    assert.doesNotMatch(template, /ingredientUnitOptions\([^,)]*\)"/, 'every picker passes the locale');
    // The count: a unit per row (the container first when there is one).
    const count = template.slice(template.indexOf('id="count-modal-form"'), template.indexOf('</form>', template.indexOf('id="count-modal-form"')));
    // (LAUNCH review add-on E1 — the amount box is an <AmountInput> with its unit picker; D2 — a row may count by container.)
    assert.match(count, /<AmountInput[\s\S]*?v-model="r\.counted"\s*v-model:unit="r\.unit"[\s\S]*?:options="ingredientUnitOptions\(r\.ingredient, locale\)"[\s\S]*?data-test="count-amount"/);
    assert.match(script, /\.map\(\(ingredient\) => \(\{ ingredient, counted: '', unit: defaultCountUnit\(ingredient\), containers: \[\] \}\)\);/);
    assert.match(script, /if \(countsPieces\(r\)\) return \{ ingredient_uuid: r\.ingredient\.uuid, counted_pieces: r\.counted \};/);
    assert.match(script, /: \{ ingredient_uuid: r\.ingredient\.uuid, counted_units: r\.counted, unit: r\.unit \};/);
    // The minimum stock is typed in a unit of the kind too.
    const { form } = ingredientForm();
    assert.match(form, /<AmountInput\s*v-model="ingForm\.min_stock_threshold"\s*v-model:unit="ingForm\.min_stock_unit"[\s\S]*?:options="holdUnits\.map\(/);
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
        // (G3: the branch stock list follows the "Show in" switch, Auto = this rule.)
        /displayAmount\(row\.quantity, row\.ingredient\?\.unit, amountDisplay\)\.amount/,
        /shownAmount\(row\.ingredient\.min_stock_threshold, row\.ingredient\.unit\)/,
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

test('F1 a Weighed / Liquid cost is typed and shown per kg / l, kept per stored unit (6 decimals)', () => {
    const { costUnit, friendlyCost, toStoredCost, formatCost } = lib('itemKind');
    const plain = (x) => `${x.amount} / ${x.unit}`;
    assert.deepEqual(['g', 'kg', 'ml', 'l', 'piece', 'box'].map((u) => costUnit(u)), ['kg', 'kg', 'l', 'l', 'piece', 'box']);
    assert.equal(plain(friendlyCost('0.000150', 'ml')), '0.150 / l');
    assert.equal(plain(friendlyCost('0.00035', 'g')), '0.350 / kg');
    assert.equal(plain(friendlyCost('1.200', 'kg')), '1.200 / kg');
    assert.equal(plain(friendlyCost('0.050', 'piece')), '0.050 / piece');
    assert.equal(toStoredCost('0.150', 'l', 'ml'), 0.00015);
    assert.equal(toStoredCost('1.2', 'kg', 'g'), 0.0012);
    assert.equal(toStoredCost('0.35', 'g', 'g'), 0.35);
    assert.equal(toStoredCost('1.2', 'g', 'kg'), 1200);
    assert.equal(toStoredCost('0.05', 'piece', 'piece'), 0.05);
    assert.equal(toStoredCost('-1', 'kg', 'g'), null);
    assert.equal(formatCost(0.15), '0.150');
    assert.equal(formatCost(0.0012345), '0.001235');
    assert.equal(formatCost(12), '12.000');

    // LAUNCH review add-on A1 — the ingredient cost is no longer typed (it
    // comes from purchases); it is still SHOWN per kg / l, read-only.
    const { script, form } = ingredientForm();
    assert.doesNotMatch(form, /ingForm\.cost_unit|data-test="cost-unit"/);
    assert.doesNotMatch(script, /default_unit_cost: costInStoredUnit\(unit\),/);
    assert.match(form, /friendlyCost\(ingModalTarget\.default_unit_cost, ingModalTarget\.unit\)\.amount/);
    const { template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(template, /data-test="ingredient-cost">[\s\S]*?<template v-else>\{\{ friendlyCost\(ing\.default_unit_cost, ing\.unit\)\.amount \}\}/);
    assert.match(template, /friendlyCost\(m\.unit_cost_at_time, m\.ingredient\?\.unit\)\.amount/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemsTab.vue').template, /data-test="prep-unit-cost">\{\{ friendlyCost\(item\.unit_cost, item\.unit\)\.amount \}\}/);
    const prep = sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').template;
    assert.match(prep, /t\('prep_items\.unit_cost', \{ unit: costUnit\(form\.unit\) \}\)/);
    assert.match(prep, /friendlyCost\(unitCost, form\.unit\)\.amount/);
});

test('F2 goods received offers pack sizes first, then kg / l, g / ml, the container; the preview reads "= 36 l · 0.150 per l"', () => {
    const { purchaseUnitOptions, defaultPurchaseUnit, purchaseUnitFactor, purchaseLinePreview, friendlyQuantity, friendlyCostPer, PIECE_UNIT } = lib('purchaseUnits');
    const milk = {
        unit: 'ml',
        auto_units: [{ name: 'l', factor: '1000' }],
        alt_units: [{ name: 'crate', factor: '12000.0000' }],
        piece_unit_label: 'bottle',
        units_per_piece: '1500.0000',
    };
    assert.deepEqual([...purchaseUnitOptions(milk).map((o) => o.label)], ['crate (12 l)', 'l', 'ml', 'bottle (1.5 l)']);
    assert.equal(defaultPurchaseUnit(milk), 'crate');
    // No pack size: the larger unit first, also for an older kg item.
    assert.equal(defaultPurchaseUnit({ unit: 'g', auto_units: [{ name: 'kg', factor: '1000' }] }), 'kg');
    assert.deepEqual([...purchaseUnitOptions({ unit: 'kg', auto_units: [{ name: 'g', factor: '0.001' }] }).map((o) => o.label)], ['kg', 'g']);
    assert.equal(defaultPurchaseUnit({ unit: 'kg', auto_units: [{ name: 'g', factor: '0.001' }] }), '');
    assert.equal(defaultPurchaseUnit({ unit: 'piece', alt_units: [], auto_units: [] }), '');
    assert.equal(defaultPurchaseUnit({ unit: 'piece', alt_units: [{ name: 'box', factor: '24' }], auto_units: [] }), 'box');

    // 3 crates at 1.800 each → 36 l into stock at 0.150 per l.
    const preview = purchaseLinePreview(purchaseUnitFactor(purchaseUnitOptions(milk), 'crate'), '3', '1.800');
    assert.equal(preview.baseQuantity, 36000);
    assert.deepEqual({ ...friendlyQuantity(preview.baseQuantity, 'ml') }, { quantity: '36', unit: 'l' });
    assert.deepEqual({ ...friendlyCostPer(preview.costPerBase, 'ml') }, { cost: '0.150', unit: 'l' });
    assert.deepEqual({ ...friendlyCostPer(0.05, 'piece') }, { cost: '0.050', unit: 'piece' });

    // LAUNCH review add-on C1 — Purchases: a line starts in the item's FIRST
    // container (pieces typed next) and the live line reads
    // "= 36 × bottle 1 l = 36 l · 0.150 per l" (lib/containers containerLineText).
    const { script } = sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue');
    assert.match(script, /line\.container_uuid = ing \? \(containersOf\(ing\)\[0\]\?\.uuid \?\? ''\) : '';/);
    assert.match(script, /line\.amount_unit = ing \? bigUnit\(ing\.unit\) : '';/);
    assert.match(script, /const text = containerLineText\(\{ container, all: containersOf\(ing\), pieces: line\.pieces, amountStored: amount, storedUnit: ing\.unit, lineCost: line\.line_cost, locale: locale\.value, leafPieces: line\.leaf_pieces \}\);/);
    assert.match(script, /if \(costPer\) text \+= ` · \$\{t\('purchases_v2\.cost_per', costPer\)\}`;/);
});

test('F3 the receipt detail reads "36 l", "Kaldi athaiba: 36 l", "Cost per l: 0.150" and keeps "Entered as 3 crate"', () => {
    const { friendlyAmount, friendlyCost } = lib('itemKind');
    assert.deepEqual({ ...friendlyAmount('36000.000', 'ml') }, { amount: '36', unit: 'l' });
    assert.deepEqual({ ...friendlyCost('0.000150', 'ml') }, { amount: '0.150', unit: 'l' });

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Show.vue');
    assert.match(template, /data-test="receipt-line-quantity">\{\{ friendlyAmount\(line\.quantity, line\.unit\)\.amount \}\}<\/span> <span class="text-xs text-slate-400">\{\{ friendlyAmount\(line\.quantity, line\.unit\)\.unit \}\}/);
    assert.match(template, /\{\{ a\.branch_name \}\}: <span data-test="receipt-allocation">\{\{ allocationText\(a\.quantity, line\.unit\) \}\}/);
    assert.match(template, /t\('purchase_receipts\.show\.cost_per_base_unit', \{ unit: friendlyCost\(line\.unit_cost, line\.unit\)\.unit, cost: friendlyCost\(line\.unit_cost, line\.unit\)\.amount \}\)/);
    // As entered stays as entered.
    assert.match(template, /t\('purchase_receipts\.show\.entered_as', \{ quantity: trimQty\(line\.purchase_quantity\), unit: enteredUnit\(line\), price: trimQty\(line\.unit_price\) \}\)/);
    assert.match(script, /const friendly = friendlyAmount\(quantity, unit\);\s*return `\$\{friendly\.amount\} \$\{friendly\.unit\}`;/);
    assert.doesNotMatch(template, /\{\{ trimQty\(line\.quantity\) \}\}|\{\{ trimQty\(a\.quantity\) \}\}/);
});

test('F4 the warehouse dialog types amounts in the kind\'s units, pack sizes or container and shows balances friendly', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue');
    assert.match(script, /ingredient\?: Ingredient \| null;/);
    assert.match(script, /const unitOptions = computed\(\(\) => entryUnitOptions\(/);
    assert.match(template, /<select v-model="entryUnit" data-test="warehouse-unit"[^>]*>\s*<option v-for="o in unitOptions"/);
    // Every write sends the unit.
    for (const call of ['receiveAndDistributeIngredientStock', 'receiveIngredientStock', 'allocateIngredientStock', 'transferIngredientStock', 'adjustIngredientStock']) {
        const at = script.indexOf(`() => ${call}(`);
        assert.ok(at > 0, call);
        assert.match(script.slice(at, script.indexOf('}),', at) + 3), /unit: wireUnit\(\)/, call);
    }
    // kg / l by default for a weighed / liquid item.
    assert.match(script, /const big = costUnit\(unit\.value\);/);
    // Balances read friendly.
    assert.match(template, /data-test="warehouse-central">\{\{ displayAmount\(summary\.central_quantity, unit, amountDisplay\)\.amount \}\}/);
    assert.match(template, /\{\{ amount\(b\.quantity\) \}\}/);
    assert.match(template, /\{\{ amount\(m\.quantity\) \}\}/);
    assert.doesNotMatch(template, /Stock \(\{\{ unit \}\}\)|Total received \(\{\{ unit \}\}\)/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/Index.vue').template, /:ingredient="warehouseDialogIngredient"/);
    assert.match(read('resources/js/lib/api/ingredientStock.ts'), /payload: \{ branch_uuid\?: string \| null; signed_quantity: string \| number; note: string \} & EntryUnitField,/);
});

test('F5 restock allocation and suggestions are typed in the kind\'s units, pack sizes or container and shown friendly', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    // Allocation: an amount + a unit per line, opened in the friendly unit, capped in the stored unit.
    // (LAUNCH review add-on E1 — the amount and its unit are one <AmountInput> with the live translation.)
    assert.match(template, /<AmountInput\s*v-model="allocateOverrides\[String\(l\.id\)\]"\s*v-model:unit="allocateUnits\[String\(l\.id\)\]"[\s\S]*?:options="ingredientUnitOptions\(restockLineIngredient\(l\), locale\)"[\s\S]*?data-test="allocate-quantity"/);
    assert.match(script, /const entry = entryFor\(line\.quantity_requested, line\.unit_at_set\);\s*allocateOverrides\[String\(line\.id\)\] = entry\.amount;\s*allocateUnits\[String\(line\.id\)\] = entry\.unit;/);
    assert.match(script, /if \(allocatedStored\(line\) > requested \+ 1e-9\) return true;/);
    assert.match(script, /await allocateRestockRequest\(allocateTarget\.value\.uuid, Object\.keys\(units\)\.length > 0 \? \{ allocations, units \} : \{ allocations \}\);/);
    // Suggestions: offered friendly, typed in any unit, sent with the unit.
    assert.match(template, /<AmountInput\s*v-model="row\.qty"\s*v-model:unit="row\.unit"[\s\S]*?:options="ingredientUnitOptions\(suggestionIngredient\(row\.suggestion\), locale\)"[\s\S]*?data-test="suggestion-quantity"/);
    assert.match(script, /const entry = entryFor\(s\.suggested_quantity, s\.unit\);/);
    assert.match(script, /quantity_requested: r\.qty,\s*unit: wireUnit\(r\.unit\),/);
    for (const field of ['current_quantity', 'avg_daily_consumption', 'target_level']) {
        assert.match(template, new RegExp(`qty\\(row\\.suggestion\\.${field}, row\\.suggestion\\.unit\\)`), field);
    }
    assert.match(read('resources/js/lib/api/inventory.ts'), /units\?: Record<number, string>;/);
});

test('F6 the edit form says to remove pack sizes and the container before another kind is saved', () => {
    const { script, form } = ingredientForm();
    // (LAUNCH review add-on A2 / A3 — the containers come with the item, the count container is one of them.)
    assert.match(script, /const kindChangeBlocked = computed<boolean>\(\(\) => ingModalMode\.value === 'edit'[\s\S]*?kindOfUnit\(ingForm\.unit\) !== kindOfUnit\(ingModalTarget\.value\.unit\)\s*&& \(\(ingModalTarget\.value\.alt_units\?\.length \?\? 0\) > 0 \|\| \(ingModalTarget\.value\.piece_unit_label \?\? ''\)\.trim\(\) !== ''\)\);/);
    assert.match(script, /if \(kindChangeBlocked\.value\) \{\s*ingModalErrors\.value = \{ unit: \[t\('item_kind\.kind_change_blocked'\)\] \};/);
    assert.match(form, /v-else-if="kindChangeBlocked"[^>]*data-test="item-kind-change-blocked">\{\{ t\('item_kind\.kind_change_blocked'\) \}\}/);
    assert.equal(en.item_kind.kind_change_blocked, 'Remove its pack sizes and count container before changing the kind.');
});

test('F7 report screens show ingredient quantities friendly (24 l), exports keep stored numbers with their unit', () => {
    const { formatQuantity } = lib('itemKind');
    assert.equal(formatQuantity('24000.000', 'ml'), '24 l');
    assert.equal(formatQuantity('-1500.000', 'g'), '-1.5 kg');
    assert.equal(formatQuantity('999.000', 'g'), '999 g');
    assert.equal(formatQuantity('12.000', 'piece'), '12 piece');
    assert.equal(formatQuantity(null, 'ml'), '—');

    const screens = {
        InventoryConsumption: ['r.consumed', 'r.current_balance', 'r.consumption_per_day', 'r.counted_units', 'r.variance_units'],
        LossWaste: ['r.total_qty', 'r.sales_consumption', 'r.total_depletion', 'r.shortfall'],
        RestockPurchasing: ['r.total_qty'],
        PortionVariance: ['r.theoretical_qty', 'r.waste_qty', 'r.count_variance_qty'],
    };
    for (const [page, fields] of Object.entries(screens)) {
        const { script, template } = sfc(`resources/js/Pages/Merchant/Reports/${page}.vue`);
        assert.match(script, /import \{ formatQuantity \} from '@\/lib\/itemKind';/, page);
        for (const field of fields) {
            const escaped = field.replace('.', '\\.');
            assert.match(template, new RegExp(`formatQuantity\\(${escaped}, r\\.unit\\)`), `${page} ${field}`);
            // (Loss & Waste's product dispositions keep their bare piece count in r.total_qty.)
            if (!(page === 'LossWaste' && field === 'r.total_qty')) {
                assert.doesNotMatch(template, new RegExp(`\\{\\{ ${escaped}( \\?\\? '—')? \\}\\}`), `${page} ${field} raw`);
            }
        }
    }
    // A total adding several ingredients says so (stored units, mixed).
    assert.match(en.reports.loss_waste.headline_labels.total_qty, /stored unit/);
    assert.match(en.reports.restock_purchasing.headline_labels.total_qty, /stored unit/);
    assert.notEqual(ar.reports.loss_waste.headline_labels.total_qty, en.reports.loss_waste.headline_labels.total_qty);
});

test('F8 Loss & Waste and Restock & Purchasing drop the mixed-unit Total qty tile; the export keeps it, labelled mixed', () => {
    for (const [page, key, money] of [['LossWaste', 'loss_waste', 'total_value'], ['RestockPurchasing', 'restock_purchasing', 'total_cost']]) {
        const { template } = sfc(`resources/js/Pages/Merchant/Reports/${page}.vue`);
        const grid = template.slice(template.indexOf('<HeadlineGrid'), template.indexOf('/>', template.indexOf('<HeadlineGrid')));
        assert.ok(grid.length > 0, page);
        assert.doesNotMatch(grid, /total_qty/, `${page} has no Total qty tile`);
        assert.match(grid, new RegExp(`t\\('reports\\.${key}\\.headline_labels\\.${money}'\\), value: payload\\.headline\\.${money}`), `${page} keeps ${money}`);
        assert.match(grid, new RegExp(`t\\('reports\\.${key}\\.headline_labels\\.event_count'\\), value: payload\\.headline\\.event_count`), `${page} keeps events`);
    }
    for (const action of ['LossWasteReportAction', 'RestockPurchasingReportAction']) {
        assert.match(read(`app/Actions/Pos/Reports/${action}.php`), /'total_qty_unit' => ReportUnits::MIXED,/, action);
    }
});

test('G1 every weighed / liquid picker also offers gallon, fl oz, lb and oz, labelled with their size (EN and AR)', () => {
    const { kindUnits, unitOptionLabel, toStoredAmount, toStoredCost, entryUnitOptions } = lib('itemKind');
    const plain = (units) => units.map((u) => `${u.value}=${u.factor}`).join(' ');
    assert.equal(plain(kindUnits('ml')), 'l=1000 ml=1 gal=3785.411784 fl oz=29.5735295625');
    assert.equal(plain(kindUnits('g')), 'kg=1000 g=1 lb=453.59237 oz=28.349523125');
    assert.equal(kindUnits('kg').find((u) => u.value === 'oz').factor, 0.028349523125);
    assert.equal(plain(kindUnits('piece')), 'piece=1');
    assert.deepEqual(['gal', 'fl oz', 'lb', 'oz'].map((u) => unitOptionLabel(u, 'en')), ['gallon (3.785 l)', 'fl oz (29.57 ml)', 'lb (453.6 g)', 'oz (28.35 g)']);
    assert.deepEqual(['gal', 'fl oz', 'lb', 'oz'].map((u) => unitOptionLabel(u, 'ar')), ['جالون (3.785 l)', 'أونصة سائلة (29.57 ml)', 'رطل (453.6 g)', 'أونصة (28.35 g)']);
    assert.equal(unitOptionLabel('kg', 'en'), 'kg');
    // Pack sizes, container, minimum, yield: stored exactly (4 decimals); cost per gallon.
    assert.equal(toStoredAmount('1', 'gal', 'ml'), 3785.4118);
    assert.equal(toStoredAmount('2', 'lb', 'g'), 907.1847);
    assert.equal(toStoredCost('3.785411784', 'gal', 'ml'), 0.001);

    const milk = { unit: 'ml', alt_units: [{ name: 'crate', factor: '12000' }], auto_units: [{ name: 'l', factor: '1000' }, { name: 'gal', factor: '3785.411784' }, { name: 'fl oz', factor: '29.5735295625' }] };
    assert.deepEqual([...entryUnitOptions(milk, 'en').map((o) => o.label)], ['ml', 'crate (12 l)', 'l', 'gallon (3.785 l)', 'fl oz (29.57 ml)']);
    const { recipeUnitOptions, recipeUnitName, recipeLineProblem, lineEntry } = lib('recipeUnits');
    assert.deepEqual([...recipeUnitOptions(milk, 'ar').map((o) => o.label)], ['ml', 'crate (12 l)', 'l', 'جالون (3.785 l)', 'أونصة سائلة (29.57 ml)']);
    assert.equal(recipeUnitName(milk, 'gal', 'ar'), 'جالون');
    assert.equal(recipeUnitName(milk, 'gal', 'en'), 'gal');
    // A line typed in gal reopens as typed.
    assert.deepEqual({ ...lineEntry({ quantity: '7570.8236', entered_unit: 'gal', entered_quantity: '2' }, 'ml') }, { quantity: '2', unit: 'gal' });
    // The tiny-amount rule still applies after converting from oz (0.001 oz of a kg item rounds to 0).
    const saffron = { unit: 'kg', auto_units: [{ name: 'g', factor: '0.001' }, { name: 'oz', factor: '0.028349523125' }] };
    assert.equal(recipeLineProblem(saffron, 'oz', '0.001').key, 'recipe_units.too_small');
    assert.equal(recipeLineProblem(saffron, 'oz', '1'), null);
    // Goods received: pack sizes, l, ml, then the US units, then the container.
    const { purchaseUnitOptions } = lib('purchaseUnits');
    assert.deepEqual([...purchaseUnitOptions({ ...milk, piece_unit_label: 'bottle', units_per_piece: '1500' }, 'en').map((o) => o.label)], ['crate (12 l)', 'l', 'ml', 'gallon (3.785 l)', 'fl oz (29.57 ml)', 'bottle (1.5 l)']);
    // (LAUNCH review add-on C1 — Purchases types a loose amount in a unit of the kind, US units included.)
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PurchaseReceipts/Create.vue').script, /const units = kindUnits\(ing\.unit\)\.filter\(\(u\) => u\.value !== ing\.unit\);/);
    // The form's "holds", cost, minimum and yield pickers label them the same way.
    assert.match(ingredientForm().script, /: unitOptionLabel\(unit, locale\.value\);/);
    assert.match(sfc('resources/js/Pages/Merchant/Inventory/PrepItemEditor.vue').script, /: unitOptionLabel\(unit, locale\.value\);/);
    assert.equal(en.item_kind.entered_in.liquid, 'Amounts are typed in l, ml, gallons or fl oz.');
});

test('G2 a conversions button shows a stock amount in every unit the item knows', () => {
    const { conversionsOf, hasConversions } = lib('itemKind');
    const milk = {
        unit: 'ml',
        alt_units: [{ name: 'crate', factor: '12000.0000' }],
        auto_units: [{ name: 'l', factor: '1000' }],
        piece_unit_label: 'bottle',
        piece_unit_label_ar: 'زجاجة',
        units_per_piece: '1500.0000',
    };
    assert.equal(conversionsOf('1500.000', milk, 'en').join(' = '), '1.5 l = 1500 ml = 0.3963 gal = 50.721 fl oz = 0.125 crate = 1 bottle');
    assert.equal(conversionsOf('1500.000', milk, 'ar').join(' = '), '1.5 l = 1500 ml = 0.3963 جالون = 50.721 أونصة سائلة = 0.125 crate = 1 زجاجة');
    assert.equal(conversionsOf('2000', { unit: 'g', alt_units: [], auto_units: [] }).join(' = '), '2 kg = 2000 g = 4.4092 lb = 70.5479 oz');
    assert.equal(conversionsOf('48', { unit: 'piece', alt_units: [{ name: 'box', factor: '24' }] }).join(' = '), '48 piece = 2 box');
    assert.equal(hasConversions(milk), true);
    assert.equal(hasConversions({ unit: 'piece', alt_units: [], auto_units: [] }), false);
    assert.equal(hasConversions({ unit: 'piece', alt_units: [{ name: 'box', factor: '24' }] }), true);

    const { script, template } = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(template, /v-if="hasConversions\(stockRowIngredient\(row\)\)"[\s\S]*?data-test="conversions-button"/);
    assert.match(template, /<bdi v-if="conversionsOpenId === row\.ingredient_id"[^>]*data-test="conversions">\{\{ conversionsOf\(row\.quantity, stockRowIngredient\(row\), locale\)\.join\(' = '\) \}\}/);
    assert.match(script, /const conversionsOpenId = ref<number \| null>\(null\);/);
    const dialog = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue').template;
    assert.equal((dialog.match(/data-test="warehouse-conversions-button"/g) ?? []).length, 2, 'warehouse total and each branch');
    assert.match(dialog, /conversionsOf\(summary\.central_quantity, conversionSource, locale\)\.join\(' = '\)/);
    assert.match(dialog, /conversionsOf\(b\.quantity, conversionSource, locale\)\.join\(' = '\)/);
});

test('G3 the stock lists switch "Show in: Auto / kg·l / g·ml", remembered per browser; counted items unaffected', () => {
    const { displayAmount, AMOUNT_DISPLAYS } = lib('itemKind');
    const plain = (q, u, m) => { const d = displayAmount(q, u, m); return `${d.amount} ${d.unit}`; };
    assert.deepEqual([...AMOUNT_DISPLAYS], ['auto', 'large', 'small']);
    assert.equal(plain('750.000', 'ml', 'auto'), '750 ml');
    assert.equal(plain('750.000', 'ml', 'large'), '0.75 l');
    assert.equal(plain('24000.000', 'ml', 'auto'), '24 l');
    assert.equal(plain('24000.000', 'ml', 'small'), '24000 ml');
    assert.equal(plain('1.5000', 'kg', 'small'), '1500 g');
    assert.equal(plain('2500.000', 'g', 'large'), '2.5 kg');
    assert.equal(plain('12.000', 'piece', 'large'), '12 piece');
    assert.equal(plain('12.000', 'piece', 'small'), '12 piece');

    // Remembered per browser, storage failures swallowed.
    const composable = read('resources/js/composables/useAmountDisplay.ts');
    assert.match(composable, /try \{\s*const value = window\.localStorage\.getItem\(AMOUNT_DISPLAY_KEY\);[\s\S]*?\} catch \{\s*return 'auto';\s*\}/);
    assert.match(composable, /try \{\s*window\.localStorage\.setItem\(AMOUNT_DISPLAY_KEY, value\);\s*\} catch \{/);
    const toggle = sfc('resources/js/Pages/Merchant/Inventory/AmountDisplaySwitch.vue').template;
    assert.match(toggle, /v-for="m in AMOUNT_DISPLAYS"/);
    assert.match(toggle, /@click="mode = m"/);
    assert.match(toggle, /t\(`item_kind\.show_modes\.\$\{m\}`\)/);
    // On the branch stock list and in the warehouse dialog.
    const index = sfc('resources/js/Pages/Merchant/Inventory/Index.vue');
    assert.match(index.template, /<AmountDisplaySwitch \/>/);
    assert.match(index.script, /const \{ mode: amountDisplay \} = useAmountDisplay\(\);/);
    const dialog = sfc('resources/js/Pages/Merchant/Inventory/IngredientStockDialog.vue');
    assert.match(dialog.template, /<AmountDisplaySwitch \/>/);
    assert.match(dialog.script, /const shown = displayAmount\(quantity, unit\.value, amountDisplay\.value\);/);
    assert.notEqual(ar.item_kind.show_modes.large, en.item_kind.show_modes.large);
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
