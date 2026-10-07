// LAUNCH combo add-on, Part A items 2–4 (LAUNCH-COMBO_WORK_ORDER.md):
//   the combo editor's "Included items" (product × quantity, upgrades at an
//   upgrade price) and "Choices" (question EN/AR, category, pick N, untick and
//   extra price per item), a price preview with the range; the meal setup
//   page (name, meal price, categories of mains, untick mains, lines, dates,
//   the server's clash message); a minus price on "Can be removed" options
//   with the "lowers the price" warning; every new text in English and
//   Arabic, with no "choice slot" wording.
// Run: node --test tests/frontend/launch-combo.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { ar, assertBilingual, assertKeysExist, en, exists, leaves, get, lib, read, sfc } from './launch-p4-support.mjs';

const items = [
    { uuid: 'beef', name: 'Beef burger', base_price: '2.000', category_id: 1 },
    { uuid: 'chicken', name: 'Chicken burger', base_price: '1.800', category_id: 1 },
    { uuid: 'fries', name: 'Fries', base_price: '1.000', category_id: 2 },
    { uuid: 'loaded', name: 'Loaded fries', base_price: '1.500', category_id: 2 },
    { uuid: 'cola', name: 'Cola', base_price: '1.000', category_id: 3 },
    { uuid: 'juice', name: 'Juice', base_price: '1.200', category_id: 3 },
    { uuid: 'water', name: 'Water', base_price: '0.300', category_id: 3 },
];
const fixed = (overrides = {}) => ({ key: 'f', id: null, kind: 'fixed', product_uuid: 'fries', quantity: 1, upgrades: [{ product_uuid: 'loaded', upgrade_price: '0.800' }], ...overrides });
const choice = (overrides = {}) => ({ key: 'c', id: null, kind: 'choice', name: 'Drinks', name_ar: 'المشروبات', category_id: 3, pick_count: 1,
    overrides: { juice: { excluded: false, extra_price: '0.300' }, water: { excluded: true, extra_price: '0' } }, ...overrides });

test('a choice offers its whole category minus the unticked items, new products included', () => {
    const { choiceItems } = lib('combo');
    assert.deepEqual(choiceItems(choice(), items).map((r) => [r.item.uuid, r.excluded, r.extra]), [['cola', false, 0], ['juice', false, 300], ['water', true, 0]]);
    // A product added to the category later is simply in, free.
    const later = [...items, { uuid: 'lemonade', name: 'Lemonade', category_id: 3 }];
    assert.deepEqual(choiceItems(choice(), later).at(-1).item.uuid, 'lemonade');
    assert.deepEqual([...choiceItems(choice({ category_id: null }), items)], []);
});

test('the price preview shows the combo price and the range with upgrades and extras, in baisas', () => {
    const { comboPriceRange } = lib('combo');
    // Family box 5.000: Beef × 2 (fixed), Fries (upgrade +0.800), Drinks pick 1 (Juice +0.300; Water unticked).
    const lines = [fixed({ product_uuid: 'beef', quantity: 2, upgrades: [] }), fixed(), choice()];
    assert.deepEqual({ ...comboPriceRange('5.000', lines, items) }, { min: '5.000', max: '6.100' });
    // Pick 4 with an extra: the dearest is 4 × 0.300.
    assert.deepEqual({ ...comboPriceRange('6.000', [choice({ pick_count: 4 })], items) }, { min: '6.000', max: '7.200' });
    // 0.1 + 0.2 never drifts.
    assert.deepEqual({ ...comboPriceRange('0.100', [fixed({ upgrades: [{ product_uuid: 'loaded', upgrade_price: '0.200' }] })], items) }, { min: '0.100', max: '0.300' });
});

test('line problems block the save', () => {
    const { lineIssues } = lib('combo');
    assert.deepEqual([...lineIssues(fixed(), items)], []);
    assert.deepEqual([...lineIssues(choice(), items)], []);
    assert.deepEqual([...lineIssues(fixed({ product_uuid: '' }), items)], ['no_item']);
    assert.deepEqual([...lineIssues(fixed({ quantity: 0 }), items)], ['quantity_range']);
    assert.deepEqual([...lineIssues(fixed({ upgrades: [{ product_uuid: 'fries', upgrade_price: '0' }] }), items)], ['upgrade_is_item']);
    assert.ok(lineIssues(fixed({ upgrades: [{ product_uuid: 'loaded', upgrade_price: '1' }, { product_uuid: 'loaded', upgrade_price: '1' }] }), items).includes('duplicate_upgrade'));
    assert.deepEqual([...lineIssues(choice({ name: ' ' }), items)], ['no_name']);
    assert.deepEqual([...lineIssues(choice({ pick_count: 21 }), items)], ['pick_range']);
    assert.deepEqual([...lineIssues(choice({ category_id: null }), items)], ['no_category']);
    const allOut = { cola: { excluded: true, extra_price: '0' }, juice: { excluded: true, extra_price: '0' }, water: { excluded: true, extra_price: '0' } };
    assert.deepEqual([...lineIssues(choice({ overrides: allOut }), items)], ['nothing_to_pick']);
});

test('the lines are saved included items first, choices with only what changes something, and come back as drafts', () => {
    const { linesPayload, draftsFrom } = lib('combo');
    const payload = linesPayload([choice({ id: 9 }), fixed({ id: 4 })], items);
    assert.deepEqual(JSON.parse(JSON.stringify(payload)), [
        { id: 4, kind: 'fixed', product_uuid: 'fries', quantity: 1, upgrades: [{ product_uuid: 'loaded', upgrade_price: '0.800' }] },
        { id: 9, kind: 'choice', name: 'Drinks', name_ar: 'المشروبات', category_id: 3, pick_count: 1, items: [
            { product_uuid: 'juice', excluded: false, extra_price: '0.300' },
            { product_uuid: 'water', excluded: true, extra_price: '0.000' },
        ] },
    ]);
    let n = 0;
    const drafts = draftsFrom([
        { id: 4, kind: 'fixed', product_uuid: 'fries', quantity: 2, upgrades: [{ product_uuid: 'loaded', upgrade_price: '0.800' }], name: null, name_ar: null, category_id: null, pick_count: null, items: [] },
        { id: 9, kind: 'choice', product_uuid: null, quantity: null, upgrades: [], name: 'Drinks', name_ar: null, category_id: 3, pick_count: 4, items: [{ product_uuid: 'water', excluded: true, extra_price: '0.000' }] },
    ], (p) => `${p}-${++n}`);
    assert.deepEqual(JSON.parse(JSON.stringify(drafts)), [
        { key: 'line-1', id: 4, kind: 'fixed', product_uuid: 'fries', quantity: 2, upgrades: [{ product_uuid: 'loaded', upgrade_price: '0.800' }] },
        { key: 'line-2', id: 9, kind: 'choice', name: 'Drinks', name_ar: '', category_id: 3, pick_count: 4, overrides: { water: { excluded: true, extra_price: '0.000' } } },
    ]);
});

test('a meal lists the mains of its categories and keeps the unticked ones out', () => {
    const { mealMains } = lib('combo');
    assert.deepEqual(mealMains([1], ['chicken'], items).map((m) => [m.item.uuid, m.ticked]), [['beef', true], ['chicken', false]]);
    assert.deepEqual([...mealMains([], [], items)], []);
});

test('the combo editor uses the lines editor: included items with upgrades, choices with untick and extra price', () => {
    assert.ok(exists('resources/js/Pages/Merchant/Catalogue/ComboLinesEditor.vue'));
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboLinesEditor.vue');
    for (const hook of ['combo-included', 'combo-fixed-line', 'fixed-item', 'fixed-quantity', 'fixed-upgrades', 'upgrade-row', 'upgrade-item', 'upgrade-price', 'add-upgrade',
        'add-fixed', 'combo-choices', 'combo-choice-line', 'choice-name', 'choice-name-ar', 'choice-category', 'choice-pick', 'choice-items', 'choice-item', 'choice-item-tick',
        'choice-item-extra', 'add-choice', 'remove-line', 'line-issues']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(template + script, 'ComboLinesEditor');
    const editor = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    assert.match(editor.template, /:self-uuid="editUuid"/);
    assert.match(editor.script, /comboPriceRange\(form\.base_price \|\| '0', form\.lines, items\.value\)/);
    assert.doesNotMatch(editor.script + editor.template, /slot/i);
});

test('the meal page: name, meal price, categories, untick mains, lines, dates, preview and the clash message', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/MealEditor.vue');
    for (const hook of ['meal-form', 'meal-name', 'meal-name-ar', 'meal-price', 'meal-status', 'meal-mains', 'meal-category', 'meal-main-list', 'meal-main-tick',
        'meal-clash', 'meal-dates', 'meal-sale-from', 'meal-sale-until', 'meal-preview', 'meal-save', 'meal-delete']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /<ComboLinesEditor v-model="form\.lines"/);
    assert.match(template, /fieldError\('category_ids'\)/);
    assert.match(script, /lines: linesPayload\(form\.lines, items\.value\),/);
    assert.match(script, /createMeal\(payload\(\)\)/);
    assert.match(script, /updateMeal\(editUuid!, payload\(\)\)/);
    assertKeysExist(template + script, 'MealEditor');
    const list = sfc('resources/js/Pages/Merchant/Catalogue/Meals.vue');
    for (const hook of ['add-meal', 'meals-list', 'meal-row', 'meal-open', 'meals-empty']) {
        assert.match(list.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(list.template + list.script, 'Meals');
    const router = read('resources/js/router.ts');
    for (const path of ["'/catalogue/meals'", "'/catalogue/meals/new'", "'/catalogue/meals/:uuid/edit'"]) {
        assert.ok(router.includes(`path: ${path}`), path);
    }
    assert.match(sfc('resources/js/Pages/Merchant/Catalogue/Index.vue').template, /data-test="open-meals"/);
    const api = read('resources/js/lib/api/catalogue.ts');
    assert.match(api, /'\/api\/meals'/);
    assert.match(api, /`\/api\/meals\/\$\{uuid\}`/);
});

test('a "Can be removed" option may lower the price, never raise it, with a warning', () => {
    const { removePriceProblem, removePricePayload, lowersPrice, removablePayload } = lib('menuExtras');
    assert.equal(removePriceProblem(''), null);
    assert.equal(removePriceProblem('0'), null);
    assert.equal(removePriceProblem('-0.100'), null);
    assert.equal(removePriceProblem('0.100'), 'above_zero');
    assert.equal(removePriceProblem('abc'), 'invalid');
    assert.equal(removePricePayload(''), '0.000');
    assert.equal(removePricePayload('-0.1'), '-0.100');
    assert.equal(removePricePayload('-0'), '0.000');
    assert.equal(lowersPrice('-0.100'), true);
    assert.equal(lowersPrice('0'), false);
    assert.equal(lowersPrice('0.200'), false);
    assert.deepEqual([...removablePayload([{ ingredient_uuid: 'c' }], { c: { ticked: true, label: 'Cheese', label_ar: '', price: '-0.1' } })].map((r) => ({ ...r })),
        [{ ingredient_uuid: 'c', label: 'Cheese', label_ar: null, price: '-0.100' }]);
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/RemovableTick.vue');
    for (const hook of ['removable-price', 'removable-price-problem', 'removable-lowers-price']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(template + script, 'RemovableTick');
});

test('every new text exists in English and Arabic, with no "choice slot" wording', () => {
    assertBilingual('combos');
    assertBilingual('meals');
    assertBilingual('menu_extras');
    for (const [label, tree] of [['en', en], ['ar', ar]]) {
        for (const key of [...leaves(get(tree, 'combos'), 'combos'), ...leaves(get(tree, 'meals'), 'meals')]) {
            assert.doesNotMatch(String(get(tree, key)), /slot|خانة|خانات/i, `${label} ${key}`);
        }
    }
    for (const key of ['price', 'price_hint', 'price_above_zero', 'price_invalid', 'lowers_price']) {
        assert.ok(get(en, `menu_extras.removable.${key}`) && get(ar, `menu_extras.removable.${key}`), key);
    }
});
