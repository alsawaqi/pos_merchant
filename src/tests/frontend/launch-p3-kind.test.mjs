// LAUNCH — item kind for ingredients (work order LAUNCH-P23, Part A).
//   A1 the create form asks what KIND of item it is (Weighed / Liquid /
//      Counted → stored in g / ml / piece), never a base unit;
//   A2 the edit form shows the kind, locked once the ingredient is used, with
//      a "stored in kg" note for an older kg / l / pack / box ingredient;
//   A3 optional "How do you buy it?" pack sizes on create, "holds [amount]
//      [unit]" with the unit limited to the kind, sent with the ingredient;
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

test('every item-kind string exists in English and Arabic', () => {
    const keys = leaves(en.item_kind, 'item_kind');
    assert.ok(keys.length >= 10, `${keys.length} keys`);
    for (const key of keys) {
        assert.equal(typeof get(en, key), 'string', `en ${key}`);
        assert.equal(typeof get(ar, key), 'string', `ar ${key}`);
        assert.notEqual(get(ar, key), get(en, key), `ar ${key} is translated`);
    }
});
