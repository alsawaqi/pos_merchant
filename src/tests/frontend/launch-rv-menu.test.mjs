// LAUNCH review add-on, part C (menu) — work order §6, owner decisions D9–D12:
//   the combo editor's main slot ("Make it a meal?"), daily hours (no longer
//   wiped), limited-time dates and cooking time; the product wizard's dates
//   and cooking time, and "Can be removed" on recipe lines; list badges
//   "Starts / Ends / Ended"; the add-on groups page's type selector (Extras or
//   Quick instructions); every new text in English and Arabic, in the new
//   `meals` and `menu_extras` blocks at the end of the locale files.
// Run: node --test tests/frontend/launch-rv-menu.test.mjs
import assert from 'node:assert/strict';
import test from 'node:test';
import { assertBilingual, assertKeysExist, ar, en, exists, lib, read, sfc } from './launch-p4-support.mjs';

const menu = () => lib('menuExtras', { Intl, Date });

test('C2 list badges: Starts before the first day, Ends while an end is set, Ended after the last day (inclusive days)', () => {
    assert.ok(exists('resources/js/lib/menuExtras.ts'), 'lib/menuExtras.ts');
    const { saleBadge, onSaleOn, saleDay, shortDay, muscatToday } = menu();
    const today = '2026-11-15';
    assert.equal(saleBadge(null, null, today), null);
    assert.equal(saleBadge('2026-11-01', null, today), null);
    assert.deepEqual({ ...saleBadge('2026-11-20', null, today) }, { kind: 'starts', date: '2026-11-20' });
    assert.deepEqual({ ...saleBadge('2026-11-20', '2026-11-30', today) }, { kind: 'starts', date: '2026-11-20' });
    assert.deepEqual({ ...saleBadge(null, '2026-11-15', today) }, { kind: 'ends', date: '2026-11-15' });
    assert.deepEqual({ ...saleBadge('2026-11-01', '2026-11-14', today) }, { kind: 'ended', date: '2026-11-14' });
    assert.equal(onSaleOn('2026-11-15', '2026-11-15', today), true);
    assert.equal(onSaleOn('2026-11-16', null, today), false);
    assert.equal(onSaleOn(null, '2026-11-14', today), false);
    assert.equal(saleDay(''), null);
    assert.equal(saleDay('15/11/2026'), null);
    assert.equal(shortDay('2026-11-01', 'en', today), '1 Nov');
    assert.match(shortDay('2027-01-05', 'en', today), /2027/);
    // Muscat is UTC+4: 21:00 UTC on 15 Nov is already 16 Nov there.
    assert.equal(muscatToday(new Date('2026-11-15T21:00:00Z')), '2026-11-16');
});

test('C1/C2 dates and cooking time: until never before from; 0..240 whole minutes; blank = not set', () => {
    const { datesProblem, cookingProblem, cookingPayload, comboCookingFigure } = menu();
    assert.equal(datesProblem('2026-11-10', '2026-11-09'), 'until_before_from');
    assert.equal(datesProblem('2026-11-10', '2026-11-10'), null);
    assert.equal(datesProblem('', '2026-11-09'), null);
    for (const ok of ['', '0', '12', 240, '240']) assert.equal(cookingProblem(ok), null, String(ok));
    for (const bad of ['-1', '241', '2.5', 'soon']) assert.equal(cookingProblem(bad), 'cooking_range', bad);
    assert.equal(cookingPayload(''), null);
    assert.equal(cookingPayload('0'), 0);
    assert.equal(cookingPayload(12), 12);
    // A combo: its own time, else its longest item.
    assert.equal(comboCookingFigure('', [5, null, 12]), 12);
    assert.equal(comboCookingFigure('8', [5, 12]), 8);
    assert.equal(comboCookingFigure('', [null]), null);
});

test('C1 the main slot: only least 1 / most 1, at most one per combo', () => {
    const { canBeMain, mainIssues } = menu();
    assert.equal(canBeMain({ min_choices: 1, max_choices: 1 }), true);
    assert.equal(canBeMain({ min_choices: 0, max_choices: 1 }), false);
    assert.equal(canBeMain({ min_choices: 4, max_choices: 4 }), false);
    assert.deepEqual([...mainIssues([{ min_choices: 1, max_choices: 1, is_main: true }, { min_choices: 1, max_choices: 2 }])].map((i) => ({ ...i })), []);
    assert.deepEqual(
        [...mainIssues([{ min_choices: 1, max_choices: 1, is_main: true }, { min_choices: 1, max_choices: 1, is_main: true }])].map((i) => ({ ...i })),
        [{ index: 1, issue: 'two_mains' }],
    );
    assert.deepEqual([...mainIssues([{ min_choices: 1, max_choices: 4, is_main: true }])].map((i) => ({ ...i })), [{ index: 0, issue: 'main_not_single' }]);
});

test('C1 warns when every item of a required slot has sale dates', () => {
    const { limitedSlotIndexes } = menu();
    const items = { a: { on_sale_until: '2026-11-30' }, b: { on_sale_from: '2026-11-01', on_sale_until: null }, c: {} };
    const itemOf = (uuid) => items[uuid];
    const slots = [
        { min_choices: 1, options: [{ product_uuid: 'a' }, { product_uuid: 'b' }] },
        { min_choices: 1, options: [{ product_uuid: 'a' }, { product_uuid: 'c' }] },
        { min_choices: 0, options: [{ product_uuid: 'a' }] },
        { min_choices: 1, options: [{ product_uuid: '' }] },
    ];
    assert.deepEqual([...limitedSlotIndexes(slots, itemOf)], [0]);
});

test('C3 "Can be removed": the ticked lines of the recipe as it stands, each once, with optional labels', () => {
    const { removablePayload, removeOptionName, removeOptionNameAr, ticksFromState, groupKind } = menu();
    const lines = [{ ingredient_uuid: 'k' }, { ingredient_uuid: 'o' }, { ingredient_uuid: '' }, { ingredient_uuid: 'k' }];
    const ticks = { k: { ticked: true, label: ' Ketchup ', label_ar: '' }, o: { ticked: false, label: '', label_ar: '' }, gone: { ticked: true, label: '', label_ar: '' } };
    assert.deepEqual([...removablePayload(lines, ticks)].map((r) => ({ ...r })), [{ ingredient_uuid: 'k', label: 'Ketchup', label_ar: null }]);
    assert.equal(removeOptionName('', 'Onion'), 'NO Onion');
    assert.equal(removeOptionName('Ketchup', 'Ketchup (Heinz 5 kg)'), 'NO Ketchup');
    assert.equal(removeOptionNameAr('', 'بصل', 'Onion'), 'بدون بصل');
    assert.equal(removeOptionNameAr('', null, 'Onion'), 'بدون Onion');
    assert.deepEqual({ ...ticksFromState([{ ingredient_uuid: 'k', label: 'Ketchup', label_ar: null }]).k }, { ticked: true, label: 'Ketchup', label_ar: '' });
    assert.equal(groupKind({ kind: 'instructions' }), 'instructions');
    assert.equal(groupKind({}), 'extras');
    assert.equal(groupKind(null), 'extras');
});

test('C1 the combo editor keeps daily hours, sends dates, cooking time and the main slot', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ComboEditor.vue');
    // The hours used to be wiped on every save (menu audit §3.2).
    assert.doesNotMatch(script, /available_from: null,/);
    assert.match(script, /available_from: form\.available_from \?/);
    assert.match(script, /form\.available_from = combo\.available_from/);
    assert.match(script, /on_sale_from: saleDay\(form\.on_sale_from\)/);
    assert.match(script, /cooking_minutes: cookingPayload\(form\.cooking_minutes\)/);
    assert.match(script, /is_main: slot\.is_main,/);
    assert.match(script, /is_main: slot\.is_main \?\? false,/);
    for (const hook of ['combo-when', 'combo-hours-from', 'combo-hours-until', 'combo-sale-from', 'combo-sale-until', 'combo-cooking', 'combo-main', 'combo-main-none', 'slot-main', 'slot-limited-warning']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(template, /:disabled="!canBeMain\(slot\) && !slot\.is_main"/);
    assert.match(script, /mainProblems\.value\.forEach/);
    assertKeysExist(template + script, 'ComboEditor');
});

test('C2/C3 the product wizard: dates and cooking time on the basics, "Can be removed" on the recipe lines', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/ProductWizard.vue');
    assert.match(script, /on_sale_from: saleDay\(form\.on_sale_from\)/);
    assert.match(script, /cooking_minutes: cookingPayload\(form\.cooking_minutes\)/);
    assert.match(script, /form\.on_sale_from = product\.on_sale_from/);
    assert.match(script, /if \(datesError\.value\) missing\.push/);
    for (const hook of ['product-sale-dates', 'product-sale-from', 'product-sale-until', 'product-cooking-minutes', 'removable-hint', 'removable-only-prints', 'review-sale-dates', 'review-cooking']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    // The tick on editable AND read-only recipe lines; catalogue.manage edits it.
    assert.equal((template.match(/<RemovableTick /g) ?? []).length, 2);
    assert.match(template, /<RemovableTick[^>]*:disabled="!canManage"/);
    // Create: in the atomic wizard call; edit: saved after the recipe.
    assert.match(script, /removable: canEditRecipes\.value && hasRecipeStep\.value \? removablePayload\(recipePayload\(\), removableTicks\.value\) : \[\]/);
    const recipeAt = script.indexOf('await updateProductRecipe(uuid');
    const removableAt = script.indexOf('await saveRemovable(uuid');
    assert.ok(recipeAt > 0 && removableAt > recipeAt, 'the ticks are saved after the recipe');
    assert.match(script, /removableOnlyPrints = computed\(\(\) => form\.stock_mode === 'cooked'\)/);
    assertKeysExist(template + script, 'ProductWizard');
    const tick = sfc('resources/js/Pages/Merchant/Catalogue/RemovableTick.vue');
    for (const hook of ['removable-tick', 'removable-checkbox', 'removable-label', 'removable-label-ar', 'removable-preview']) {
        assert.match(tick.template, new RegExp(`data-test="${hook}"`), hook);
    }
    assertKeysExist(tick.template + tick.script, 'RemovableTick');
    const api = read('resources/js/lib/api/menuExtras.ts');
    assert.match(api, /`\/api\/products\/\$\{productUuid\}\/removable`/);
});

test('C2/C4 the catalogue list shows date badges; the add-on groups page picks Extras or Quick instructions', () => {
    const { script, template } = sfc('resources/js/Pages/Merchant/Catalogue/Index.vue');
    assert.match(template, /data-test="sale-badge"/);
    assert.match(script, /saleBadge\(prod\.on_sale_from, prod\.on_sale_until, todayMuscat\)/);
    for (const hook of ['group-kind', 'group-kind-extras', 'group-kind-instructions', 'kind-badge', 'instruction-free']) {
        assert.match(template, new RegExp(`data-test="${hook}"`), hook);
    }
    assert.match(script, /kind: agForm\.kind,/);
    assert.match(script, /price_delta: instruction \? '0' : aoForm\.price_delta/);
    assert.match(script, /linked_product_uuid: instruction \? null :/);
    assert.match(template, /v-if="!aoIsInstruction" class="rounded-lg border border-slate-200 p-3"/);
    assertKeysExist(template + script, 'Index');
});

test('C7 every new text exists in English and Arabic, in new blocks at the end of the locale files', () => {
    assertBilingual('meals');
    assertBilingual('menu_extras');
    for (const [label, tree] of [['en', en], ['ar', ar]]) {
        assert.deepEqual(Object.keys(tree).slice(-2), ['meals', 'menu_extras'], `${label}: the new blocks are last`);
    }
});
